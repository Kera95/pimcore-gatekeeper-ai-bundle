<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tsf\GatekeeperAiBundle\Model\ApplyReport;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;
use Tsf\GatekeeperAiBundle\Service\Apply\ApplyRunner;
use Tsf\GatekeeperAiBundle\Service\Review\ProposalDecider;

use function count;
use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:ai:apply',
    description: 'Writes approved proposals into their objects, all fields of an object in one save, and reports the score before and after'
)]
final class ApplyCommand extends Command
{
    use ProposalFilterTrait;

    public function __construct(
        private readonly ProposalDecider $decider,
        private readonly ApplyRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addFilterOptions(true);
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many objects')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be written, field by field, without saving');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $rows = $this->decider->select($this->idsOption($input), $input->getOption('class') ?: null, $input->getOption('language') ?: null, $this->objectOption($input), ProposalStatus::Approved);
        $rows = array_values(array_filter($rows, static fn (Proposal $row): bool => $row->getStatus() === ProposalStatus::Approved));

        $limit = $input->getOption('limit');
        if ($limit !== null) {
            $keep = [];
            $rows = array_values(array_filter($rows, static function (Proposal $row) use (&$keep, $limit): bool {
                $keep[$row->getObjectId()] = true;

                return count($keep) <= max(0, (int) $limit);
            }));
        }

        if (count($rows) === 0) {
            $io->success('No approved proposals to apply.');

            return Command::SUCCESS;
        }

        $report = $this->runner->apply($rows, $dryRun);

        $io->section($dryRun ? 'Dry run: what would be written' : 'Applied');
        $io->table(
            ['Proposal', 'Object', 'Field', 'Outcome', 'Value / reason'],
            array_map(static fn (array $row): array => [
                $row['proposal']->getId(),
                sprintf('%s %d', $row['proposal']->getClassName(), $row['proposal']->getObjectId()),
                $row['proposal']->getLabel(),
                $row['outcome'],
                $row['detail'] ?? mb_strimwidth(preg_replace('/\s+/u', ' ', $row['proposal']->getProposedValue()) ?? '', 0, 80, '…'),
            ], $report->getRows())
        );

        if (count($report->getScores()) > 0) {
            $io->section('Completeness before → after');
            $lines = [];
            foreach ($report->getScores() as $objectId => $scores) {
                $parts = [];
                foreach ($scores['after'] as $label => $after) {
                    $before = $scores['before'][$label] ?? null;
                    $parts[] = sprintf('%s %s → %d%%', $label, $before === null ? '–' : $before . '%', $after);
                }
                $lines[] = sprintf('%d %s: %s', $objectId, $scores['key'], implode(', ', $parts));
            }
            $io->listing($lines);
        }

        $applied = $report->count($dryRun ? ApplyReport::WOULD_APPLY : ApplyReport::APPLIED);
        $io->success(sprintf(
            '%d value(s) %s on %d object(s); %d stale, %d blocked, %d object(s) missing.',
            $applied,
            $dryRun ? 'would be written' : 'written',
            $report->getObjectCount(),
            $report->count(ApplyReport::STALE),
            $report->count(ApplyReport::BLOCKED),
            $report->count(ApplyReport::MISSING)
        ));

        return $report->count(ApplyReport::BLOCKED) > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
