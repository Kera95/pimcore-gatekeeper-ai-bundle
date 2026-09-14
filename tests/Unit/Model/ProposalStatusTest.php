<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Model;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;

final class ProposalStatusTest extends Unit
{
    public function testOnlyMachineOwnedStatusesAreReplaceable(): void
    {
        $replaceable = array_filter(ProposalStatus::cases(), static fn (ProposalStatus $s): bool => $s->isReplaceable());

        self::assertSame(['pending', 'invalid', 'stale'], array_map(static fn (ProposalStatus $s): string => $s->value, array_values($replaceable)));
    }

    public function testValuesListsEveryCase(): void
    {
        self::assertSame(['pending', 'approved', 'rejected', 'applied', 'invalid', 'stale', 'blocked_by_gate'], ProposalStatus::values());
    }
}
