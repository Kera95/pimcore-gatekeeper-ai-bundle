<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Model;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;

final class ProposalTest extends Unit
{
    public function testCreateIsPendingWithoutAReasonAndInvalidWithOne(): void
    {
        $at = new \DateTimeImmutable('2026-09-14 10:00:00');
        $pending = Proposal::create(12, 'Product', 'default', 'de', 'title', null, 'Kopfhörer', 'src', 'kb', 'prefix', '1', 'claude-opus-5', 10, 20, 30, 40, null, $at);

        self::assertNull($pending->getId());
        self::assertSame(ProposalStatus::Pending, $pending->getStatus());
        self::assertNull($pending->getInvalidReason());
        self::assertSame('title [de]', $pending->getLabel());
        self::assertSame($at, $pending->getCreatedAt());
        self::assertSame($at, $pending->getUpdatedAt());
        self::assertNull($pending->getAppliedAt());

        $invalid = Proposal::create(12, 'Product', 'default', '', 'sku', null, 'X', 'src', 'kb', 'prefix', '1', 'claude-opus-5', invalidReason: 'too long');
        self::assertSame(ProposalStatus::Invalid, $invalid->getStatus());
        self::assertSame('too long', $invalid->getInvalidReason());
        self::assertSame('sku', $invalid->getLabel());
    }

    public function testRoundTripsThroughTheTableShape(): void
    {
        $proposal = Proposal::create(12, 'Product', 'print', 'en', 'description', '', 'Over-ear.', 'src', 'kb', 'prefix', '1', 'claude-opus-5', 10, 20, 30, 40, null, new \DateTimeImmutable('2026-09-14 10:00:00'));

        $row = $proposal->toArray();
        self::assertArrayNotHasKey('id', $row);
        self::assertSame('2026-09-14 10:00:00', $row['created_at']);
        self::assertNull($row['applied_at']);

        $hydrated = Proposal::fromArray(array_merge($row, ['id' => '7', 'applied_at' => '2026-09-14 11:00:00', 'status' => 'applied']));
        self::assertSame(7, $hydrated->getId());
        self::assertSame(12, $hydrated->getObjectId());
        self::assertSame('print', $hydrated->getProfile());
        self::assertSame('', $hydrated->getCurrentValue());
        self::assertSame('Over-ear.', $hydrated->getProposedValue());
        self::assertSame(ProposalStatus::Applied, $hydrated->getStatus());
        self::assertSame([10, 20, 30, 40], [$hydrated->getInputTokens(), $hydrated->getOutputTokens(), $hydrated->getCacheReadTokens(), $hydrated->getCacheWriteTokens()]);
        self::assertSame('2026-09-14 11:00:00', $hydrated->getAppliedAt()?->format('Y-m-d H:i:s'));
    }
}
