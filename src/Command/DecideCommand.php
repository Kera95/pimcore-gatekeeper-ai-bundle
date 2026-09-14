<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;
use Tsf\GatekeeperAiBundle\Service\Review\ProposalDecider;

use function count;
use function sprintf;

/**
 * Shared shape of approve and reject: explicit --id list, or --all with the filters; never
 * everything by accident
 */
abstract class DecideCommand extends Command
{
    use ProposalFilterTrait;

    public function __construct(
        protected readonly ProposalDecider $decider,
    ) {
        parent::__construct();
    }

    abstract protected function verb(): string;

    abstract protected function done(): string;

    /**
     * The status --all selects; null for every status (the decider still only moves what applies)
     */
    abstract protected function fromStatus(): ?ProposalStatus;

    /**
     * What the decision applies to, for messages
     */
    abstract protected function appliesTo(): string;

    /**
     * @param Proposal[] $proposals
     *
     * @return array{changed: int, skipped: int}
     */
    abstract protected function decide(array $proposals): array;

    protected function configure(): void
    {
        $this->addFilterOptions(true);
        $this->addOption('all', null, InputOption::VALUE_NONE, sprintf('%s every matching row instead of naming ids', ucfirst($this->verb())));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ids = $this->idsOption($input);

        if ($ids === null && !$input->getOption('all')) {
            $io->error(sprintf('Name the rows: --id 1,2,3, or --all together with --class / --language / --object to %s every matching %s row.', $this->verb(), $this->appliesTo()));

            return Command::INVALID;
        }

        $rows = $this->decider->select($ids, $input->getOption('class') ?: null, $input->getOption('language') ?: null, $this->objectOption($input), $this->fromStatus());
        if (count($rows) === 0) {
            $io->warning('No matching proposals.');

            return Command::SUCCESS;
        }

        $result = $this->decide($rows);
        foreach ($rows as $row) {
            $io->writeln(sprintf('%s: %s', $this->describeRow($row), $row->getStatus()->value), OutputInterface::VERBOSITY_VERBOSE);
        }

        $io->success(sprintf('%d proposal(s) %s%s.', $result['changed'], $this->done(), $result['skipped'] > 0 ? sprintf(', %d left alone (not %s)', $result['skipped'], $this->appliesTo()) : ''));

        return Command::SUCCESS;
    }
}
