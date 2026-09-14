<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tsf\GatekeeperAiBundle\Model\PlanGroup;
use Tsf\GatekeeperAiBundle\Model\RunPlan;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Propose\ProposeRunner;
use Tsf\GatekeeperAiBundle\Service\Propose\RunPlanner;
use Tsf\GatekeeperAiBundle\Service\Provider\EnrichmentProviderInterface;
use Tsf\GatekeeperAiBundle\Service\Provider\FakeProvider;

use function count;
use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:ai:propose',
    description: 'Asks the model for the fields the Gatekeeper reports missing and stores the answers as proposals for review'
)]
final class ProposeCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly RunPlanner $planner,
        private readonly ProposeRunner $runner,
        private readonly EnrichmentProviderInterface $provider,
        private readonly FakeProvider $fake,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Only this DataObject class')
            ->addOption('gate-profile', 'p', InputOption::VALUE_REQUIRED, 'Only fields reported missing by this Gatekeeper profile')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'Only this language')
            ->addOption('fields', 'f', InputOption::VALUE_REQUIRED, 'Only these field paths, comma separated')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many objects, in the order the Gatekeeper reports them')
            ->addOption('estimate', null, InputOption::VALUE_NONE, 'Print what the run would cost and exit; nothing is sent')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run the whole pipeline with the fake provider: nothing is sent, nothing is stored')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Ask again for objects that already have proposals for the same input');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fields = $input->getOption('fields');
        $limit = $input->getOption('limit');

        $plan = $this->planner->plan(
            $input->getOption('class') ?: null,
            $input->getOption('gate-profile') ?: null,
            $input->getOption('language') ?: null,
            $fields ? array_values(array_filter(array_map('trim', explode(',', (string) $fields)))) : null,
            $limit !== null ? max(0, (int) $limit) : null
        );

        $this->printPlan($io, $plan);
        if ($plan->isEmpty()) {
            $io->success('Nothing to propose.');

            return Command::SUCCESS;
        }

        if ($input->getOption('estimate')) {
            return $this->estimate($io, $plan);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $provider = $dryRun ? $this->fake : $this->provider;
        $io->section(sprintf('%s via %s (%s)', $dryRun ? 'Dry run' : 'Proposing', $provider->getName(), $provider->getModel()));

        $report = static function (string $level, string $line) use ($io): void {
            $verbosity = match ($level) {
                'field' => OutputInterface::VERBOSITY_VERY_VERBOSE,
                default => OutputInterface::VERBOSITY_VERBOSE,
            };
            $io->writeln(($level === 'group' ? '<comment>' . $line . '</comment>' : $line), $verbosity);
        };

        $summary = $this->runner->run($plan, $provider, !$dryRun, (bool) $input->getOption('force'), $report);

        $io->definitionList(
            ['Requests' => $summary->getRequests()],
            ['Proposals pending' => $summary->getPending()],
            ['Proposals invalid' => $summary->getInvalid()],
            ['Kept (already decided)' => $summary->getKeptDecided()],
            ['Objects skipped (fresh)' => $summary->getObjectsSkippedFresh()],
            ['Objects failed' => $summary->getObjectsFailed()],
            ['Tokens in / cache read / cache write / out' => sprintf('%d / %d / %d / %d', $summary->getUsage()->getInputTokens(), $summary->getUsage()->getCacheReadTokens(), $summary->getUsage()->getCacheWriteTokens(), $summary->getUsage()->getOutputTokens())],
            ['Cost' => $this->runner->formatCost($summary->getCost()) . ($dryRun ? ' (fake provider)' : '')]
        );

        if (count($summary->getProblems()) > 0) {
            $io->warning(sprintf('%d object(s) failed:', $summary->getObjectsFailed()));
            $io->listing($summary->getProblems());
        }

        if ($summary->isStopped()) {
            $io->error(sprintf('Run stopped: %s', $summary->getStoppedBecause()));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run finished; nothing was stored.' : sprintf('%d proposal(s) stored. Review them with tsf:gatekeeper:ai:review.', $summary->getPending() + $summary->getInvalid()));

        return Command::SUCCESS;
    }

    private function printPlan(SymfonyStyle $io, RunPlan $plan): void
    {
        $io->section('Plan');
        $io->table(
            ['Group', 'Objects', 'Fields', 'Field paths'],
            array_map(static fn (PlanGroup $group): array => [
                $group->getLabel(),
                $group->getObjectCount(),
                $group->getFieldCount(),
                implode(', ', array_keys($group->getSpecs())),
            ], $plan->getGroups())
        );
        $io->text(sprintf('%d object(s), %d request(s), %d field value(s); ceilings: %d objects, %s per run.', $plan->getObjectCount(), $plan->getRequestCount(), $plan->getFieldCount(), $this->settings->getMaxObjectsPerRun(), $this->runner->formatCost($this->settings->getMaxCostPerRun())));

        if (count($plan->getSkipped()) > 0) {
            $lines = [];
            foreach ($plan->getSkipped() as $reason => $n) {
                $lines[] = sprintf('%d missing field(s) left out: %s', $n, $reason);
            }
            $io->listing($lines);
        }
    }

    private function estimate(SymfonyStyle $io, RunPlan $plan): int
    {
        $estimate = $this->runner->estimate($plan);

        $io->section(sprintf('Estimate for %s', $this->settings->getModel()));
        $io->table(
            ['Group', 'Objects', 'Fields', 'Prefix tokens', 'Object tokens', 'Output tokens', 'Cost'],
            array_map(fn (array $row): array => [
                $row['label'],
                $row['objects'],
                $row['fields'],
                number_format($row['prefix_tokens']),
                number_format($row['input_tokens']),
                number_format($row['output_tokens']),
                $this->runner->formatCost($row['cost']),
            ], $estimate['groups'])
        );
        $io->text([
            sprintf('Estimated total: %s for %d request(s) (chars / 4 for input, %d output tokens per field; the prefix is written once per group and read from cache afterwards).', $this->runner->formatCost($estimate['cost']), $estimate['requests'], $this->settings->getAvgOutputTokensPerField()),
            sprintf('Ceiling: %s per run (tsf_gatekeeper_ai.limits.max_cost_per_run).', $this->runner->formatCost($this->settings->getMaxCostPerRun())),
        ]);

        if ($estimate['cost'] > $this->settings->getMaxCostPerRun()) {
            $io->warning('The estimate is above the cost ceiling; the run would stop part-way. Use --limit, --class or --fields, or raise the ceiling.');
        }

        return Command::SUCCESS;
    }
}
