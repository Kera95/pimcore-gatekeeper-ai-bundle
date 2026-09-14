<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Apply;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\ValidationException;
use Psr\Log\LoggerInterface;
use Tsf\GatekeeperAiBundle\Model\ApplyReport;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;
use Tsf\GatekeeperAiBundle\Service\Propose\ObjectSnapshot;
use Tsf\GatekeeperBundle\EventListener\DataObjectListener;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessChecker;
use Tsf\GatekeeperBundle\Service\FieldReader;
use Tsf\GatekeeperBundle\Service\ResultStore;

use function count;
use function is_array;
use function sprintf;

/**
 * Writes approved proposals into their objects: all rows of one object in one save, each one
 * checked against the object as it is now (same source hash, field still empty), through the
 * generated setters so versioning applies. The save skips the Gatekeeper's warn/block step - the
 * object gets more complete, not less - but is still scored, and the score before and after is
 * reported. A save Pimcore refuses marks the rows blocked; nothing is retried blind.
 */
final class ApplyRunner
{
    public function __construct(
        private readonly ProposalStore $store,
        private readonly ObjectSnapshot $snapshot,
        private readonly FieldReader $fieldReader,
        private readonly EmptinessChecker $emptiness,
        private readonly ResultStore $results,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Proposal[] $proposals approved rows; anything else is ignored
     */
    public function apply(array $proposals, bool $dryRun = false): ApplyReport
    {
        $report = new ApplyReport();

        /** @var array<int, Proposal[]> $byObject */
        $byObject = [];
        foreach ($proposals as $proposal) {
            if ($proposal->getStatus() === ProposalStatus::Approved && $proposal->getId() !== null) {
                $byObject[$proposal->getObjectId()][] = $proposal;
            }
        }

        foreach ($byObject as $objectId => $rows) {
            $this->applyToObject($objectId, $rows, $dryRun, $report);
        }

        $this->logger->info(sprintf(
            'Gatekeeper AI: apply %s - %d object(s), %d applied, %d stale, %d blocked, %d missing',
            $dryRun ? 'dry run' : 'run',
            $report->getObjectCount(),
            $report->count($dryRun ? ApplyReport::WOULD_APPLY : ApplyReport::APPLIED),
            $report->count(ApplyReport::STALE),
            $report->count(ApplyReport::BLOCKED),
            $report->count(ApplyReport::MISSING)
        ));

        return $report;
    }

    /**
     * @param Proposal[] $rows
     */
    private function applyToObject(int $objectId, array $rows, bool $dryRun, ApplyReport $report): void
    {
        $object = $this->load($objectId);
        if ($object === null) {
            foreach ($rows as $row) {
                $report->add($row, ApplyReport::MISSING);
            }

            return;
        }

        $filled = $this->snapshot->filledFields($object);
        $class = $object->getClass();
        $writable = [];

        foreach ($rows as $row) {
            $definition = $this->fieldReader->getDefinition($class, $row->getFieldName());
            if ($definition === null) {
                $this->markStale($row, $report, 'the field no longer exists on the class');

                continue;
            }
            $current = $this->fieldReader->readAll($object, $row->getFieldName(), $row->getLanguage() === '' ? null : $row->getLanguage())[0] ?? null;
            if ($this->emptiness->isFilled($definition, $current)) {
                $this->markStale($row, $report, 'the field is no longer empty');

                continue;
            }
            if ($row->getSourceHash() !== $this->snapshot->sourceHash($filled, $row->getLanguage())) {
                $this->markStale($row, $report, 'the object changed since the proposal was made');

                continue;
            }
            $writable[] = [$row, $definition];
        }

        if (count($writable) === 0) {
            return;
        }

        if ($dryRun) {
            foreach ($writable as [$row]) {
                $report->add($row, ApplyReport::WOULD_APPLY);
            }

            return;
        }

        $before = $this->scores($objectId);
        foreach ($writable as [$row, $definition]) {
            $object->set($row->getFieldName(), $this->valueFor($definition, $row->getProposedValue()), $row->getLanguage() === '' ? null : $row->getLanguage());
        }

        try {
            // the gate would refuse a published object that is still incomplete after this save;
            // the object gets more complete, so score and store it and skip warn/block
            $object->save([DataObjectListener::SKIP_GATE_PARAMETER => true]);
        } catch (ValidationException $e) {
            foreach ($writable as [$row]) {
                $this->store->updateStatus((int) $row->getId(), ProposalStatus::BlockedByGate, $e->getMessage());
                $report->add($row, ApplyReport::BLOCKED, $e->getMessage());
            }
            $this->logger->warning(sprintf('Gatekeeper AI: apply refused for %s %d: %s', $rows[0]->getClassName(), $objectId, $e->getMessage()));

            return;
        }

        foreach ($writable as [$row]) {
            $this->store->updateStatus((int) $row->getId(), ProposalStatus::Applied);
            $report->add($row, ApplyReport::APPLIED);
        }
        $report->addScores($objectId, (string) $object->getKey(), $before, $this->scores($objectId));
    }

    private function markStale(Proposal $row, ApplyReport $report, string $reason): void
    {
        $this->store->updateStatus((int) $row->getId(), ProposalStatus::Stale, $reason);
        $report->add($row, ApplyReport::STALE, $reason);
    }

    /**
     * The stored text as the setter wants it: a list for multiselects, the string otherwise
     */
    private function valueFor(Data $definition, string $stored): mixed
    {
        if ($definition instanceof Data\Multiselect) {
            $decoded = json_decode($stored, true);

            return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [$stored];
        }

        return $stored;
    }

    /**
     * @return array<string, int> "profile/language" => score
     */
    private function scores(int $objectId): array
    {
        $scores = [];
        foreach ($this->results->findByObject($objectId) as $row) {
            $scores[$row->getLabel()] = $row->getScore();
        }

        return $scores;
    }

    private function load(int $objectId): ?Concrete
    {
        try {
            // bypass the runtime cache: the object must be read as it is in the database now
            $object = Concrete::getById($objectId, ['force' => true]);
        } catch (\Throwable) {
            return null;
        }

        return $object instanceof Concrete ? $object : null;
    }
}
