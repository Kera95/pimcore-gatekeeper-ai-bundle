<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Review;

use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;

use function count;
use function in_array;

/**
 * Records a person's decision on proposals: approve moves pending rows on, reject takes pending
 * and approved rows out. Anything else (applied, invalid, stale, blocked) is left alone and
 * counted, so a typo in --id cannot resurrect or bury a row by accident.
 */
final class ProposalDecider
{
    public function __construct(
        private readonly ProposalStore $store,
    ) {
    }

    /**
     * @param Proposal[] $proposals
     *
     * @return array{changed: int, skipped: int} rows moved, rows left in a status the decision does not apply to
     */
    public function approve(array $proposals): array
    {
        return $this->move($proposals, [ProposalStatus::Pending], ProposalStatus::Approved);
    }

    /**
     * @param Proposal[] $proposals
     *
     * @return array{changed: int, skipped: int}
     */
    public function reject(array $proposals): array
    {
        return $this->move($proposals, [ProposalStatus::Pending, ProposalStatus::Approved], ProposalStatus::Rejected);
    }

    /**
     * The rows a decision targets: explicit ids, or every row matching the filters
     *
     * @param int[]|null $ids
     *
     * @return Proposal[]
     */
    public function select(?array $ids, ?string $className, ?string $language, ?int $objectId, ?ProposalStatus $status): array
    {
        if ($ids !== null) {
            $rows = [];
            foreach ($ids as $id) {
                $row = $this->store->get($id);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }

            return $rows;
        }

        return $this->store->find($className, $status, $objectId, $language);
    }

    /**
     * @param Proposal[]       $proposals
     * @param ProposalStatus[] $from
     *
     * @return array{changed: int, skipped: int}
     */
    private function move(array $proposals, array $from, ProposalStatus $to): array
    {
        $changed = 0;
        foreach ($proposals as $proposal) {
            $id = $proposal->getId();
            if ($id === null || !in_array($proposal->getStatus(), $from, true)) {
                continue;
            }
            $this->store->updateStatus($id, $to);
            $changed++;
        }

        return ['changed' => $changed, 'skipped' => count($proposals) - $changed];
    }
}
