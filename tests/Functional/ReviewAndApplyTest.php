<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Functional;

use Pimcore\Model\DataObject\Concrete;
use Tsf\GatekeeperAiBundle\Model\ApplyReport;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;
use Tsf\GatekeeperAiBundle\Service\Apply\ApplyRunner;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;
use Tsf\GatekeeperAiBundle\Service\Propose\ObjectSnapshot;
use Tsf\GatekeeperAiBundle\Tests\Support\Fixture\ClassFixtures;
use Tsf\GatekeeperAiBundle\Tests\Support\FunctionalTestCase;
use Tsf\GatekeeperBundle\EventListener\DataObjectListener;

/**
 * review, approve, reject and apply on rows the propose command (fake provider) produced
 */
final class ReviewAndApplyTest extends FunctionalTestCase
{
    private ProposalStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var ProposalStore $store */
        $store = $this->service(ProposalStore::class);
        $this->store = $store;
    }

    public function testReviewListsInEveryFormat(): void
    {
        $product = $this->proposeFor($this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false));

        $table = $this->runCommand('tsf:gatekeeper:ai:review');
        self::assertSame(0, $table->getStatusCode());
        self::assertStringContainsString('GkProduct ' . $product->getId(), $table->getDisplay());
        self::assertStringContainsString('title [de]', $table->getDisplay());
        self::assertStringContainsString('3 row(s) shown. All statuses: pending 3', $table->getDisplay());

        $csv = $this->runCommand('tsf:gatekeeper:ai:review', ['--format' => 'csv', '--language' => 'en'])->getDisplay();
        self::assertStringStartsWith("id,class,object_id,field,language,status,current_value,proposed_value", $csv);
        self::assertSame(2, substr_count($csv, "\n"), 'header plus the one en row');
        self::assertStringContainsString(',GkProduct,' . $product->getId() . ',description,en,pending,,"Proposed description",', $csv);

        $md = $this->runCommand('tsf:gatekeeper:ai:review', ['--format' => 'md', '--status' => 'all'])->getDisplay();
        self::assertStringStartsWith("| Id | Object | Field | Status | Proposed | Reason |\n| --- |", $md);
        self::assertSame(3, substr_count($md, '| pending |'));

        self::assertStringContainsString('No proposals match.', $this->runCommand('tsf:gatekeeper:ai:review', ['--status' => 'applied'])->getDisplay());
        self::assertSame(2, $this->runCommand('tsf:gatekeeper:ai:review', ['--format' => 'xlsx'])->getStatusCode());
        self::assertSame(2, $this->runCommand('tsf:gatekeeper:ai:review', ['--status' => 'maybe'])->getStatusCode());
    }

    public function testApproveAndRejectNeedIdsOrAllAndOnlyMoveWhatApplies(): void
    {
        $product = $this->proposeFor($this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false));
        $rows = $this->rowsByLabel($product->getId());

        self::assertSame(2, $this->runCommand('tsf:gatekeeper:ai:approve')->getStatusCode(), 'refuses to guess');

        $approve = $this->runCommand('tsf:gatekeeper:ai:approve', ['--id' => $rows['title [de]']->getId() . ',' . $rows['description [de]']->getId()]);
        self::assertStringContainsString('2 proposal(s) approved.', $approve->getDisplay());

        $again = $this->runCommand('tsf:gatekeeper:ai:approve', ['--id' => (string) $rows['title [de]']->getId()]);
        self::assertStringContainsString('0 proposal(s) approved, 1 left alone (not pending).', $again->getDisplay());

        $reject = $this->runCommand('tsf:gatekeeper:ai:reject', ['--all' => true, '--language' => 'de']);
        self::assertStringContainsString('2 proposal(s) rejected.', $reject->getDisplay());

        $rows = $this->rowsByLabel($product->getId());
        self::assertSame(ProposalStatus::Rejected, $rows['title [de]']->getStatus());
        self::assertSame(ProposalStatus::Rejected, $rows['description [de]']->getStatus());
        self::assertSame(ProposalStatus::Pending, $rows['description [en]']->getStatus());

        $all = $this->runCommand('tsf:gatekeeper:ai:approve', ['--all' => true, '--class' => ClassFixtures::PRODUCT]);
        self::assertStringContainsString('1 proposal(s) approved.', $all->getDisplay(), '--all takes pending rows only');
    }

    public function testApplyWritesApprovedValuesInOneSaveAndReportsTheScore(): void
    {
        $product = $this->proposeFor($this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false));
        $this->runCommand('tsf:gatekeeper:ai:approve', ['--all' => true]);

        $dry = $this->runCommand('tsf:gatekeeper:ai:apply', ['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode());
        self::assertStringContainsString('would apply', $dry->getDisplay());
        self::assertStringContainsString('3 value(s) would be written on 1 object(s)', $dry->getDisplay());
        self::assertNull($this->localized($product->getId(), 'title', 'de'));

        $apply = $this->runCommand('tsf:gatekeeper:ai:apply');
        $display = $apply->getDisplay();
        self::assertSame(0, $apply->getStatusCode(), $display);
        self::assertStringContainsString('3 value(s) written on 1 object(s); 0 stale, 0 blocked, 0 object(s) missing.', $display);
        self::assertStringContainsString('default/de 50% → 100%', $display);
        self::assertStringContainsString('default/en 75% → 100%', $display);

        self::assertSame('Proposed title', $this->localized($product->getId(), 'title', 'de'));
        self::assertSame('Proposed description', $this->localized($product->getId(), 'description', 'de'));
        self::assertSame('Proposed description', $this->localized($product->getId(), 'description', 'en'));
        self::assertFalse(Concrete::getById($product->getId(), ['force' => true])?->isPublished() ?? true, 'the publish state is untouched');

        foreach ($this->store->findByObject($product->getId()) as $row) {
            self::assertSame(ProposalStatus::Applied, $row->getStatus());
            self::assertNotNull($row->getAppliedAt());
        }

        self::assertStringContainsString('No approved proposals to apply.', $this->runCommand('tsf:gatekeeper:ai:apply')->getDisplay());
    }

    public function testApplyMarksRowsStaleWhenTheObjectChangedOrTheFieldWasFilled(): void
    {
        $product = $this->proposeFor($this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false));
        $this->runCommand('tsf:gatekeeper:ai:approve', ['--all' => true]);

        // a person fills the German title by hand
        /** @var Concrete $edited */
        $edited = Concrete::getById($product->getId(), ['force' => true]);
        $edited->set('title', 'Kabel', 'de');
        $edited->save();

        $apply = $this->runCommand('tsf:gatekeeper:ai:apply');
        $display = $apply->getDisplay();
        $rows = $this->rowsByLabel($product->getId());

        self::assertSame(ProposalStatus::Stale, $rows['title [de]']->getStatus());
        self::assertSame('the field is no longer empty', $rows['title [de]']->getInvalidReason());
        self::assertSame(ProposalStatus::Stale, $rows['description [de]']->getStatus(), 'the German input the model saw is not what it is now');
        self::assertSame('the object changed since the proposal was made', $rows['description [de]']->getInvalidReason());
        self::assertSame(ProposalStatus::Applied, $rows['description [en]']->getStatus(), 'the English input is untouched');
        self::assertStringContainsString('1 value(s) written on 1 object(s); 2 stale', $display);

        self::assertSame('Kabel', $this->localized($product->getId(), 'title', 'de'), 'the human value stays');
        self::assertNull($this->localized($product->getId(), 'description', 'de'));
        self::assertSame('Proposed description', $this->localized($product->getId(), 'description', 'en'));
    }

    public function testApplyOnAPublishedBlockGatedObjectSkipsTheGateAndStillScores(): void
    {
        // published but incomplete: only the core's own bypass gets it past the block gate
        $product = $this->product(['sku' => 'SKU-9', 'name' => 'Done', 'title' => ['en' => 'Done', 'de' => 'Fertig'], 'description' => ['en' => 'd']]);
        $product->save([DataObjectListener::SKIP_GATE_PARAMETER => true]);
        $this->runCommand('tsf:gatekeeper:ai:propose');
        $this->runCommand('tsf:gatekeeper:ai:approve', ['--all' => true]);

        $apply = $this->runCommand('tsf:gatekeeper:ai:apply');

        self::assertSame(0, $apply->getStatusCode(), $apply->getDisplay());
        self::assertStringContainsString('1 value(s) written on 1 object(s)', $apply->getDisplay());
        self::assertTrue(Concrete::getById($product->getId(), ['force' => true])?->isPublished() ?? false);
    }

    public function testApplyReportsMissingObjects(): void
    {
        $product = $this->proposeFor($this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false));
        $this->runCommand('tsf:gatekeeper:ai:approve', ['--all' => true]);
        $product->delete();

        /** @var ApplyRunner $runner */
        $runner = $this->service(ApplyRunner::class);
        $report = $runner->apply($this->store->find(null, ProposalStatus::Approved));

        self::assertSame(3, $report->count(ApplyReport::MISSING));
        self::assertSame(0, $report->count(ApplyReport::APPLIED));
    }

    private function proposeFor(Concrete $object): Concrete
    {
        $object->save();
        $tester = $this->runCommand('tsf:gatekeeper:ai:propose');
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        return $object;
    }

    /**
     * Straight from the table, so Pimcore's language fallback cannot mask an empty field
     */
    private function localized(int $objectId, string $field, string $language): ?string
    {
        $value = $this->connection()->fetchOne(
            sprintf('SELECT %s FROM object_localized_data_%s WHERE ooo_id = :id AND language = :language', $field, ClassFixtures::PRODUCT),
            ['id' => $objectId, 'language' => $language]
        );

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * @return array<string, Proposal>
     */
    private function rowsByLabel(int $objectId): array
    {
        $rows = [];
        foreach ($this->store->findByObject($objectId) as $row) {
            $rows[$row->getLabel()] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function complete(): array
    {
        return ['sku' => 'SKU-9', 'name' => 'Done', 'title' => ['en' => 'Done', 'de' => 'Fertig'], 'description' => ['en' => 'd', 'de' => 'd']];
    }
}
