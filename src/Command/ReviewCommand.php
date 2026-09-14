<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;

use function count;
use function in_array;
use function sprintf;

#[AsCommand(
    name: 'tsf:gatekeeper:ai:review',
    description: 'Lists proposals for review, as a table or as CSV / Markdown for a spreadsheet or a ticket'
)]
final class ReviewCommand extends Command
{
    use ProposalFilterTrait;

    public const FORMATS = ['table', 'csv', 'md'];

    public const TABLE_VALUE_WIDTH = 70;

    public function __construct(
        private readonly ProposalStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addFilterOptions(false);
        $this
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'One of ' . implode(', ', ProposalStatus::values()) . ', or "all"', ProposalStatus::Pending->value)
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'table, csv or md', 'table')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'At most this many rows')
            ->addOption('full', null, InputOption::VALUE_NONE, 'Do not shorten values in the table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');
        if (!in_array($format, self::FORMATS, true)) {
            $io->error(sprintf('Unknown format "%s"; one of %s.', $format, implode(', ', self::FORMATS)));

            return Command::INVALID;
        }

        try {
            $status = $this->statusOption($input, ProposalStatus::Pending);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $limit = $input->getOption('limit');

        $rows = $this->store->find(
            $input->getOption('class') ?: null,
            $status,
            $this->objectOption($input),
            $input->getOption('language') ?: null,
            $limit !== null ? max(0, (int) $limit) : null
        );

        if (count($rows) === 0) {
            if ($format === 'table') {
                $io->success('No proposals match.');
            }

            return Command::SUCCESS;
        }

        match ($format) {
            'csv' => $this->csv($output, $rows),
            'md' => $this->markdown($output, $rows),
            default => $this->table($io, $rows, (bool) $input->getOption('full')),
        };

        if ($format === 'table') {
            $counts = $this->store->countByStatus($input->getOption('class') ?: null);
            $io->text(sprintf('%d row(s) shown. All statuses%s: %s.', count($rows), $input->getOption('class') ? ' of ' . $input->getOption('class') : '', implode(', ', array_map(static fn (string $s, int $n): string => $s . ' ' . $n, array_keys($counts), $counts))));
            $io->text('Approve with tsf:gatekeeper:ai:approve --id 1,2,3 (or --all with the same filters), reject with tsf:gatekeeper:ai:reject, write with tsf:gatekeeper:ai:apply.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param Proposal[] $rows
     */
    private function table(SymfonyStyle $io, array $rows, bool $full): void
    {
        $io->table(
            ['Id', 'Object', 'Field', 'Status', 'Current', 'Proposed', 'Reason'],
            array_map(fn (Proposal $p): array => [
                $p->getId(),
                sprintf('%s %d', $p->getClassName(), $p->getObjectId()),
                $p->getLabel(),
                $p->getStatus()->value,
                $this->shorten($p->getCurrentValue() ?? '', $full),
                $this->shorten($p->getProposedValue(), $full),
                $this->shorten($p->getInvalidReason() ?? '', $full),
            ], $rows)
        );
    }

    /**
     * @param Proposal[] $rows
     */
    private function csv(OutputInterface $output, array $rows): void
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return;
        }
        fputcsv($handle, ['id', 'class', 'object_id', 'field', 'language', 'status', 'current_value', 'proposed_value', 'invalid_reason', 'model', 'created_at'], ',', '"', '\\');
        foreach ($rows as $p) {
            fputcsv($handle, [$p->getId(), $p->getClassName(), $p->getObjectId(), $p->getFieldName(), $p->getLanguage(), $p->getStatus()->value, $p->getCurrentValue() ?? '', $p->getProposedValue(), $p->getInvalidReason() ?? '', $p->getModel(), $p->getCreatedAt()->format('Y-m-d H:i:s')], ',', '"', '\\');
        }
        rewind($handle);
        $output->write((string) stream_get_contents($handle), false, OutputInterface::OUTPUT_RAW);
        fclose($handle);
    }

    /**
     * @param Proposal[] $rows
     */
    private function markdown(OutputInterface $output, array $rows): void
    {
        $lines = ['| Id | Object | Field | Status | Proposed | Reason |', '| --- | --- | --- | --- | --- | --- |'];
        foreach ($rows as $p) {
            $lines[] = sprintf(
                '| %d | %s %d | %s | %s | %s | %s |',
                $p->getId() ?? 0,
                $p->getClassName(),
                $p->getObjectId(),
                $p->getLabel(),
                $p->getStatus()->value,
                $this->cell($p->getProposedValue()),
                $this->cell($p->getInvalidReason() ?? '')
            );
        }
        $output->write(implode("\n", $lines) . "\n", false, OutputInterface::OUTPUT_RAW);
    }

    private function cell(string $text): string
    {
        return str_replace(['|', "\r\n", "\n"], ['\\|', '<br>', '<br>'], $text);
    }

    private function shorten(string $text, bool $full): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($full || mb_strlen($text) <= self::TABLE_VALUE_WIDTH) {
            return $text;
        }

        return mb_substr($text, 0, self::TABLE_VALUE_WIDTH - 1) . '…';
    }
}
