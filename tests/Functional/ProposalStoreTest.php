<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Functional;

use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;
use Tsf\GatekeeperAiBundle\Tests\Support\Fixture\ClassFixtures;
use Tsf\GatekeeperAiBundle\Tests\Support\FunctionalTestCase;

final class ProposalStoreTest extends FunctionalTestCase
{
    private ProposalStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ProposalStore $store */
        $store = $this->service(ProposalStore::class);
        $this->store = $store;
    }

    public function testSaveInsertsAndGetReadsTheRowBack(): void
    {
        $id = $this->store->save($this->proposal(12, 'title', 'de', 'Kopfhörer'));
        self::assertNotNull($id);

        $row = $this->store->get($id);
        self::assertNotNull($row);
        self::assertSame($id, $row->getId());
        self::assertSame(12, $row->getObjectId());
        self::assertSame(ClassFixtures::PRODUCT, $row->getClassName());
        self::assertSame('title', $row->getFieldName());
        self::assertSame('de', $row->getLanguage());
        self::assertSame('Kopfhörer', $row->getProposedValue());
        self::assertSame(ProposalStatus::Pending, $row->getStatus());
        self::assertSame([100, 20, 80, 0], [$row->getInputTokens(), $row->getOutputTokens(), $row->getCacheReadTokens(), $row->getCacheWriteTokens()]);
        self::assertSame('2026-09-14 10:00:00', $row->getCreatedAt()->format('Y-m-d H:i:s'));

        self::assertNull($this->store->get($id + 1000));
    }

    public function testSaveReplacesPendingInvalidAndStaleRowsOfTheSameTarget(): void
    {
        $first = $this->store->save($this->proposal(12, 'title', 'de', 'v1'));
        self::assertNotNull($first);

        foreach ([ProposalStatus::Pending, ProposalStatus::Invalid, ProposalStatus::Stale] as $status) {
            $this->store->updateStatus($first, $status, 'reason');

            $again = $this->store->save($this->proposal(12, 'title', 'de', 'v2 after ' . $status->value));
            self::assertSame($first, $again, 'the row keeps its id');

            $row = $this->store->get($first);
            self::assertNotNull($row);
            self::assertSame('v2 after ' . $status->value, $row->getProposedValue());
            self::assertSame(ProposalStatus::Pending, $row->getStatus());
            self::assertNull($row->getInvalidReason());
        }

        self::assertCount(1, $this->store->findByObject(12));
    }

    public function testSaveNeverTouchesDecidedOrAppliedRows(): void
    {
        $id = $this->store->save($this->proposal(12, 'title', 'de', 'kept'));
        self::assertNotNull($id);

        foreach ([ProposalStatus::Approved, ProposalStatus::Rejected, ProposalStatus::Applied, ProposalStatus::BlockedByGate] as $status) {
            $this->store->updateStatus($id, $status);

            self::assertNull($this->store->save($this->proposal(12, 'title', 'de', 'replaced?')), $status->value);

            $row = $this->store->get($id);
            self::assertNotNull($row);
            self::assertSame('kept', $row->getProposedValue());
            self::assertSame($status, $row->getStatus());
        }
    }

    public function testTheSameFieldInAnotherLanguageOrObjectIsAnotherRow(): void
    {
        $this->store->save($this->proposal(12, 'title', 'de'));
        $this->store->save($this->proposal(12, 'title', 'en'));
        $this->store->save($this->proposal(13, 'title', 'de'));
        $this->store->save($this->proposal(13, 'name'));

        self::assertCount(2, $this->store->findByObject(12));
        self::assertCount(2, $this->store->findByObject(13));
        self::assertSame(
            ['12 title [de]', '12 title [en]', '13 name', '13 title [de]'],
            array_map(static fn (Proposal $p): string => $p->getObjectId() . ' ' . $p->getLabel(), $this->store->find())
        );
    }

    public function testFindNarrowsByClassStatusObjectLanguageAndLimit(): void
    {
        $de = $this->store->save($this->proposal(12, 'title', 'de'));
        $this->store->save($this->proposal(12, 'title', 'en'));
        $invalid = $this->store->save($this->proposal(12, 'description', 'en', 'x', 'too short'));
        self::assertNotNull($de);
        self::assertNotNull($invalid);
        $this->store->updateStatus($de, ProposalStatus::Approved);

        self::assertCount(3, $this->store->find(ClassFixtures::PRODUCT));
        self::assertSame([], $this->store->find(ClassFixtures::CATEGORY));
        self::assertSame([$de], array_map(static fn (Proposal $p): ?int => $p->getId(), $this->store->find(null, ProposalStatus::Approved)));
        self::assertSame([$invalid], array_map(static fn (Proposal $p): ?int => $p->getId(), $this->store->find(null, ProposalStatus::Invalid)));
        self::assertCount(2, $this->store->find(null, null, 12, 'en'));
        self::assertCount(1, $this->store->find(null, null, null, null, 1));
        self::assertSame([], $this->store->find(null, null, 99));
    }

    public function testUpdateStatusStampsAppliedAtForAppliedRowsOnly(): void
    {
        $id = $this->store->save($this->proposal(12, 'title', 'de'));
        self::assertNotNull($id);
        $at = new \DateTimeImmutable('2026-09-14 12:00:00');

        $this->store->updateStatus($id, ProposalStatus::Stale, 'object changed', $at);
        $row = $this->store->get($id);
        self::assertNotNull($row);
        self::assertSame(ProposalStatus::Stale, $row->getStatus());
        self::assertSame('object changed', $row->getInvalidReason());
        self::assertSame('2026-09-14 12:00:00', $row->getUpdatedAt()->format('Y-m-d H:i:s'));
        self::assertNull($row->getAppliedAt());

        $this->store->updateStatus($id, ProposalStatus::Applied, null, $at);
        $row = $this->store->get($id);
        self::assertNotNull($row);
        self::assertNull($row->getInvalidReason());
        self::assertSame('2026-09-14 12:00:00', $row->getAppliedAt()?->format('Y-m-d H:i:s'));
    }

    public function testCountByStatusListsEveryStatus(): void
    {
        $approved = $this->store->save($this->proposal(12, 'title', 'de'));
        $this->store->save($this->proposal(12, 'title', 'en'));
        $this->store->save($this->proposal(12, 'description', 'en', 'x', 'too short'));
        self::assertNotNull($approved);
        $this->store->updateStatus($approved, ProposalStatus::Approved);

        self::assertSame(
            ['pending' => 1, 'approved' => 1, 'rejected' => 0, 'applied' => 0, 'invalid' => 1, 'stale' => 0, 'blocked_by_gate' => 0],
            $this->store->countByStatus()
        );
        self::assertSame(0, array_sum($this->store->countByStatus(ClassFixtures::CATEGORY)));
    }

    public function testDeleteByObjectRemovesItsRowsOnly(): void
    {
        $this->store->save($this->proposal(12, 'title', 'de'));
        $this->store->save($this->proposal(12, 'title', 'en'));
        $this->store->save($this->proposal(13, 'title', 'de'));

        self::assertSame(2, $this->store->deleteByObject(12));
        self::assertSame([], $this->store->findByObject(12));
        self::assertCount(1, $this->store->findByObject(13));
        self::assertSame(0, $this->store->deleteByObject(12));
    }
}
