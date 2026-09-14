<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;

#[AsCommand(
    name: 'tsf:gatekeeper:ai:reject',
    description: 'Rejects pending or approved proposals; a re-run never replaces a rejected row'
)]
final class RejectCommand extends DecideCommand
{
    protected function verb(): string
    {
        return 'reject';
    }

    protected function done(): string
    {
        return 'rejected';
    }

    protected function fromStatus(): ?ProposalStatus
    {
        return null;
    }

    protected function appliesTo(): string
    {
        return 'pending or approved';
    }

    protected function decide(array $proposals): array
    {
        return $this->decider->reject($proposals);
    }
}
