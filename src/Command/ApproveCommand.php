<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;

#[AsCommand(
    name: 'tsf:gatekeeper:ai:approve',
    description: 'Approves pending proposals so the next apply run writes them'
)]
final class ApproveCommand extends DecideCommand
{
    protected function verb(): string
    {
        return 'approve';
    }

    protected function done(): string
    {
        return 'approved';
    }

    protected function fromStatus(): ?ProposalStatus
    {
        return ProposalStatus::Pending;
    }

    protected function appliesTo(): string
    {
        return 'pending';
    }

    protected function decide(array $proposals): array
    {
        return $this->decider->approve($proposals);
    }
}
