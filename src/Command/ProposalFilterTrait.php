<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;

use function sprintf;

/**
 * The options the review, approve, reject and apply commands share for picking rows
 */
trait ProposalFilterTrait
{
    private function addFilterOptions(bool $withIds): void
    {
        $this
            ->addOption('class', 'c', InputOption::VALUE_REQUIRED, 'Only this DataObject class')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED, 'Only this language')
            ->addOption('object', 'o', InputOption::VALUE_REQUIRED, 'Only this object id');
        if ($withIds) {
            $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Only these proposal ids, comma separated');
        }
    }

    /**
     * @return int[]|null
     */
    private function idsOption(InputInterface $input): ?array
    {
        $ids = $input->getOption('id');
        if ($ids === null || $ids === '') {
            return null;
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $ids)), static fn (int $id): bool => $id > 0));
    }

    private function objectOption(InputInterface $input): ?int
    {
        $object = $input->getOption('object');

        return $object === null || $object === '' ? null : (int) $object;
    }

    private function statusOption(InputInterface $input, ProposalStatus $default): ?ProposalStatus
    {
        $status = (string) $input->getOption('status');
        if ($status === '') {
            return $default;
        }
        if ($status === 'all') {
            return null;
        }

        return ProposalStatus::tryFrom($status) ?? throw new \InvalidArgumentException(sprintf('Unknown status "%s"; one of %s or all.', $status, implode(', ', ProposalStatus::values())));
    }

    private function describeRow(Proposal $proposal): string
    {
        return sprintf('#%d %s %d %s', $proposal->getId() ?? 0, $proposal->getClassName(), $proposal->getObjectId(), $proposal->getLabel());
    }
}
