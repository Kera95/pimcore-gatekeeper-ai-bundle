<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Propose;

use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;
use Tsf\GatekeeperAiBundle\Model\ClassSettings;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\RunPlan;
use Tsf\GatekeeperAiBundle\Model\RunSummary;
use Tsf\GatekeeperAiBundle\Model\Usage;
use Tsf\GatekeeperAiBundle\Model\ValidationResult;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Context\KnowledgeBase;
use Tsf\GatekeeperAiBundle\Service\Field\ProposalValidator;
use Tsf\GatekeeperAiBundle\Service\Prompt\PromptBuilder;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;
use Tsf\GatekeeperAiBundle\Service\Provider\EnrichmentProviderInterface;
use Tsf\GatekeeperAiBundle\Service\Provider\Pricing;
use Tsf\GatekeeperAiBundle\Service\Provider\ProviderException;
use Tsf\GatekeeperAiBundle\Service\TokenEstimator;

use function count;
use function sprintf;

/**
 * Executes a RunPlan: one cached prefix per group, one request per object, every returned
 * value validated and stored as a pending or invalid proposal. Stops at the configured object
 * and cost ceilings and on fatal provider errors; skips objects that already have a proposal
 * for the same input, knowledge base and prompt version; logs and skips everything else.
 */
final class ProposeRunner
{
    public const STOP_MAX_OBJECTS = 'max_objects_per_run reached';

    public const STOP_MAX_COST = 'max_cost_per_run reached';

    public function __construct(
        private readonly Settings $settings,
        private readonly KnowledgeBase $knowledgeBase,
        private readonly PromptBuilder $prompts,
        private readonly ObjectSnapshot $snapshot,
        private readonly ProposalValidator $validator,
        private readonly ProposalStore $store,
        private readonly Pricing $pricing,
        private readonly TokenEstimator $estimator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(string, string): void|null $report receives ("object"|"field"|"group", line) as the run goes
     */
    public function run(RunPlan $plan, EnrichmentProviderInterface $provider, bool $persist = true, bool $force = false, ?callable $report = null): RunSummary
    {
        $summary = new RunSummary();
        $report ??= static function (string $level, string $line): void {
        };
        $processed = [];
        $maxObjects = $this->settings->getMaxObjectsPerRun();
        $maxCost = $this->settings->getMaxCostPerRun();

        foreach ($plan->getGroups() as $group) {
            $class = $this->settings->getClass($group->getClassName());
            if ($class === null) {
                continue;
            }
            $context = $this->knowledgeBase->assemble($group->getClassName());
            $prefix = $this->prompts->prefix($class, $context, array_values($group->getSpecs()), $group->getLanguage());
            $report('group', sprintf('%s: %d object(s), prefix hash %s, kb hash %s', $group->getLabel(), $group->getObjectCount(), hash('sha256', implode("\n\x00\n", $prefix)), $context->getHash()));

            foreach ($group->getTargets() as $objectId => $fields) {
                if (!isset($processed[$objectId]) && count($processed) >= $maxObjects) {
                    $summary->stop(self::STOP_MAX_OBJECTS);

                    break 2;
                }
                if ($summary->getCost() >= $maxCost) {
                    $summary->stop(self::STOP_MAX_COST);

                    break 2;
                }

                $object = $this->load($objectId);
                if ($object === null) {
                    $summary->addFailed(sprintf('%s %d: object not found', $group->getLabel(), $objectId));

                    continue;
                }

                $specs = $group->specsFor($objectId);
                $filled = $this->snapshot->filledFields($object);
                $message = $this->prompts->userMessage($group->getClassName(), $group->getLanguage(), $filled, $specs);
                $request = $this->prompts->request($prefix, $message, $specs);
                $sourceHash = $this->snapshot->sourceHash($filled, $group->getLanguage(), $class->getEnrich());

                if (!$force && $this->isFresh($objectId, $group->getLanguage(), $specs, $sourceHash, $context->getHash(), $request->getPrefixHash())) {
                    $summary->addSkippedFresh();
                    $report('object', sprintf('%s %d %s: skipped, proposals for the same input exist', $group->getLabel(), $objectId, $object->getKey()));

                    continue;
                }
                // only objects that are actually sent count towards max_objects_per_run, so a
                // re-run after a ceiling stop walks past the ones that already have proposals
                $processed[$objectId] = true;

                try {
                    $response = $provider->generate($request);
                } catch (ProviderException $e) {
                    $summary->addFailed(sprintf('%s %d %s: %s', $group->getLabel(), $objectId, $object->getKey(), $e->getMessage()));
                    if ($e->isFatal()) {
                        $summary->stop($e->getMessage());

                        break 2;
                    }

                    continue;
                }

                $cost = $this->pricing->cost($response->getUsage());
                $summary->addRequest($response->getUsage(), $cost);

                if (!$response->isUsable()) {
                    $problem = sprintf('%s %d %s: %s', $group->getLabel(), $objectId, $object->getKey(), $response->describeProblem());
                    $summary->addFailed($problem);
                    $this->logger->warning('Gatekeeper AI: ' . $problem, ['request_id' => $response->getRequestId()]);

                    continue;
                }

                $outcomes = [];
                foreach ($specs as $spec) {
                    $result = $this->check($spec, $response->getValues());
                    $proposal = Proposal::create(
                        $objectId,
                        $group->getClassName(),
                        $group->profileOf($objectId, $spec->getPath()),
                        $group->getLanguage(),
                        $spec->getPath(),
                        $this->snapshot->currentValue($object, $spec->getPath(), $group->getLanguage()),
                        $result->getValue(),
                        $sourceHash,
                        $context->getHash(),
                        $request->getPrefixHash(),
                        PromptBuilder::PROMPT_VERSION,
                        $response->getModel(),
                        $response->getUsage()->getInputTokens(),
                        $response->getUsage()->getOutputTokens(),
                        $response->getUsage()->getCacheReadTokens(),
                        $response->getUsage()->getCacheWriteTokens(),
                        $result->getReason()
                    );

                    $outcome = $result->isValid() ? 'pending' : 'invalid';
                    if ($persist && $this->store->save($proposal) === null) {
                        $outcome = 'kept';
                        $summary->addKeptDecided();
                    } elseif ($result->isValid()) {
                        $summary->addPending();
                    } else {
                        $summary->addInvalid();
                    }
                    $outcomes[] = $spec->getPath() . '=' . $outcome;
                    $report('field', sprintf('%s %d %s: %s -> %s%s', $group->getLabel(), $objectId, $object->getKey(), $spec->getPath(), $outcome, $result->isValid() ? '' : ' (' . $result->getReason() . ')'));
                }

                $report('object', sprintf(
                    '%s %d %s: %s; tokens in %d / cache read %d / cache write %d / out %d; %s',
                    $group->getLabel(),
                    $objectId,
                    $object->getKey(),
                    implode(', ', $outcomes),
                    $response->getUsage()->getInputTokens(),
                    $response->getUsage()->getCacheReadTokens(),
                    $response->getUsage()->getCacheWriteTokens(),
                    $response->getUsage()->getOutputTokens(),
                    $this->formatCost($cost)
                ));
            }
        }

        $this->logger->info(sprintf(
            'Gatekeeper AI: propose run %s - %d request(s), %d pending, %d invalid, %d kept, %d skipped, %d failed, tokens in %d / cache read %d / cache write %d / out %d, %s%s',
            $summary->isStopped() ? 'stopped' : 'finished',
            $summary->getRequests(),
            $summary->getPending(),
            $summary->getInvalid(),
            $summary->getKeptDecided(),
            $summary->getObjectsSkippedFresh(),
            $summary->getObjectsFailed(),
            $summary->getUsage()->getInputTokens(),
            $summary->getUsage()->getCacheReadTokens(),
            $summary->getUsage()->getCacheWriteTokens(),
            $summary->getUsage()->getOutputTokens(),
            $this->formatCost($summary->getCost()),
            $summary->isStopped() ? ' (' . $summary->getStoppedBecause() . ')' : ''
        ));

        return $summary;
    }

    /**
     * What the plan would cost without sending anything: the prefix once as a cache write and
     * then as reads (at full price on every request when prompt caching is off), the object
     * message at full price, the output at the configured average.
     *
     * @return array{groups: array<int, array{label: string, objects: int, fields: int, prefix_tokens: int, input_tokens: int, output_tokens: int, cost: float}>, objects: int, requests: int, fields: int, cost: float}
     */
    public function estimate(RunPlan $plan): array
    {
        $rows = [];
        $total = 0.0;
        $perField = $this->settings->getAvgOutputTokensPerField();
        $caching = $this->settings->getAnthropic()['prompt_caching'];

        foreach ($plan->getGroups() as $group) {
            $class = $this->settings->getClass($group->getClassName()) ?? new ClassSettings($group->getClassName(), [], [], '');
            $context = $this->knowledgeBase->assemble($group->getClassName());
            $prefixTokens = $this->estimator->estimate(implode("\n\n", $this->prompts->prefix($class, $context, array_values($group->getSpecs()), $group->getLanguage())));

            $input = 0;
            foreach ($group->getTargets() as $objectId => $fields) {
                $object = $this->load($objectId);
                $filled = $object === null ? [] : $this->snapshot->filledFields($object);
                $input += $this->estimator->estimate($this->prompts->userMessage($group->getClassName(), $group->getLanguage(), $filled, $group->specsFor($objectId)));
            }
            $output = $group->getFieldCount() * $perField;
            $objects = $group->getObjectCount();

            // with caching off the prefix is read at full price on every request
            $usage = $caching
                ? new Usage($input, $output, $objects > 1 ? $prefixTokens * ($objects - 1) : 0, $objects > 0 ? $prefixTokens : 0)
                : new Usage($input + $prefixTokens * $objects, $output);
            $cost = $this->pricing->cost($usage);
            $total += $cost;
            $rows[] = [
                'label' => $group->getLabel(),
                'objects' => $objects,
                'fields' => $group->getFieldCount(),
                'prefix_tokens' => $prefixTokens,
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cost' => $cost,
            ];
        }

        return ['groups' => $rows, 'objects' => $plan->getObjectCount(), 'requests' => $plan->getRequestCount(), 'fields' => $plan->getFieldCount(), 'cost' => $total];
    }

    public function formatCost(float $cost): string
    {
        return sprintf('$%.4f', $cost);
    }

    /**
     * True when every requested field already has a proposal built from exactly this input:
     * the same object message, knowledge base, prompt prefix (instructions, class instructions,
     * field descriptions) and prompt version
     *
     * @param FieldSpec[] $specs
     */
    private function isFresh(int $objectId, string $language, array $specs, string $sourceHash, string $kbHash, string $prefixHash): bool
    {
        $existing = [];
        foreach ($this->store->find(null, null, $objectId, $language) as $proposal) {
            $existing[$proposal->getFieldName()] = $proposal;
        }

        foreach ($specs as $spec) {
            $proposal = $existing[$spec->getPath()] ?? null;
            if ($proposal === null
                || $proposal->getSourceHash() !== $sourceHash
                || $proposal->getKbHash() !== $kbHash
                || $proposal->getPrefixHash() !== $prefixHash
                || $proposal->getPromptVersion() !== PromptBuilder::PROMPT_VERSION) {
                return false;
            }
        }

        return count($specs) > 0;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function check(FieldSpec $spec, array $values): ValidationResult
    {
        if (!array_key_exists($spec->getPath(), $values)) {
            return ValidationResult::invalid('missing from the answer.');
        }

        return $this->validator->validate($spec, $values[$spec->getPath()]);
    }

    private function load(int $objectId): ?Concrete
    {
        try {
            $object = Concrete::getById($objectId);
        } catch (\Throwable) {
            return null;
        }

        return $object instanceof Concrete ? $object : null;
    }
}
