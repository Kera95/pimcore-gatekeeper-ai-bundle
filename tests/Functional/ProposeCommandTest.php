<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Functional;

use Pimcore\Model\DataObject\Concrete;
use Tsf\GatekeeperAiBundle\Model\EnrichmentResponse;
use Tsf\GatekeeperAiBundle\Model\PlanGroup;
use Tsf\GatekeeperAiBundle\Model\Proposal;
use Tsf\GatekeeperAiBundle\Model\ProposalStatus;
use Tsf\GatekeeperAiBundle\Model\Usage;
use Tsf\GatekeeperAiBundle\Service\Prompt\PromptBuilder;
use Tsf\GatekeeperAiBundle\Service\ProposalStore;
use Tsf\GatekeeperAiBundle\Service\Propose\ObjectSnapshot;
use Tsf\GatekeeperAiBundle\Service\Propose\RunPlanner;
use Tsf\GatekeeperAiBundle\Service\Provider\EnrichmentProviderInterface;
use Tsf\GatekeeperAiBundle\Service\Provider\FakeProvider;
use Tsf\GatekeeperAiBundle\Service\Provider\ProviderException;
use Tsf\GatekeeperAiBundle\Tests\Support\Fixture\ClassFixtures;
use Tsf\GatekeeperAiBundle\Tests\Support\FunctionalTestCase;

/**
 * The propose command end to end on the fake provider: the Gatekeeper rows of the test classes
 * (GkProduct: sku, name, title, description in en and de; enrich title, description, seo_title)
 * become groups, requests and proposal rows.
 */
final class ProposeCommandTest extends FunctionalTestCase
{
    private FakeProvider $fake;

    private ProposalStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var FakeProvider $fake */
        $fake = $this->service(EnrichmentProviderInterface::class);
        $fake->reset();
        $this->fake = $fake;
        /** @var ProposalStore $store */
        $store = $this->service(ProposalStore::class);
        $this->store = $store;
    }

    public function testPlansOnlyEnrichableMissingFieldsInTheConfiguredLanguages(): void
    {
        $incomplete = $this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false);
        $incomplete->save();
        $this->product($this->complete())->save();
        $this->category(null)->save();

        /** @var RunPlanner $planner */
        $planner = $this->service(RunPlanner::class);
        $plan = $planner->plan();

        self::assertSame(['GkCategory [en]', 'GkProduct [de]', 'GkProduct [en]'], array_map(static fn (PlanGroup $g): string => $g->getLabel(), $plan->getGroups()));
        [$category, $de, $en] = $plan->getGroups();
        self::assertSame(['title'], array_keys($category->getSpecs()));
        self::assertSame(['title', 'description', 'seo_title'], array_keys($de->getSpecs()), 'every enrichable localized field is described in the prefix');
        self::assertSame([$incomplete->getId() => ['title', 'description']], $de->getTargets());
        self::assertSame([$incomplete->getId() => ['description']], $en->getTargets());
        self::assertSame(2, $plan->getObjectCount());
        self::assertSame(3, $plan->getRequestCount());
        self::assertSame([RunPlanner::SKIP_NOT_ENRICHED => 1], $plan->getSkipped(), 'the category also misses "name", which is not enriched');

        $limited = $planner->plan(ClassFixtures::PRODUCT, null, 'de', ['description'], 1);
        self::assertCount(1, $limited->getGroups());
        self::assertSame([$incomplete->getId() => ['description']], $limited->getGroups()[0]->getTargets());
        self::assertSame([RunPlanner::SKIP_NOT_REQUESTED => 1], $limited->getSkipped());
    }

    public function testProposesAndStoresPendingRowsWithTheHashes(): void
    {
        $product = $this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'USB cable']], false);
        $product->save();

        $tester = $this->runCommand('tsf:gatekeeper:ai:propose', ['--class' => ClassFixtures::PRODUCT, '-vv' => true]);
        $output = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), $output);
        self::assertStringContainsString('Proposing via fake (claude-opus-5)', $output);
        self::assertStringContainsString('3 proposal(s) stored', $output);

        $rows = $this->store->findByObject($product->getId());
        self::assertSame(['description [de]', 'title [de]', 'description [en]'], array_map(static fn (Proposal $p): string => $p->getLabel(), $rows));
        foreach ($rows as $row) {
            self::assertSame(ProposalStatus::Pending, $row->getStatus());
            self::assertSame('Proposed ' . strtolower($row->getFieldName()), $row->getProposedValue());
            self::assertNull($row->getCurrentValue());
            self::assertSame('default', $row->getProfile());
            self::assertSame(PromptBuilder::PROMPT_VERSION, $row->getPromptVersion());
            self::assertSame(64, strlen($row->getSourceHash()));
            self::assertSame(64, strlen($row->getPrefixHash()));
            self::assertSame(hash('sha256', ''), $row->getKbHash(), 'no knowledge base in the test kernel');
            self::assertGreaterThan(0, $row->getCacheReadTokens());
        }

        $requests = $this->fake->getRequests();
        self::assertCount(2, $requests, 'one request per (object, language)');
        self::assertStringContainsString("Filled fields:\n- sku: SKU-1\n- name: Cable\n- title [en]: USB cable\n\nProduce: title, description\n", $requests[0]->getUserMessage());
        self::assertSame(['title', 'description', 'seo_title'], $requests[0]->getSchema()['required'], 'the schema is the group\'s, so the cached prefix stays the same for every object');
        self::assertSame($requests[0]->getSchema(), $requests[1]->getSchema());
        self::assertStringContainsString("Produce: description\n", $requests[1]->getUserMessage());
        self::assertStringStartsWith("# Fields to produce (language: de)\n", $requests[0]->getPrefixBlocks()[2]);
    }

    public function testASecondRunSkipsFreshObjectsUnlessForced(): void
    {
        $this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false)->save();

        $this->runCommand('tsf:gatekeeper:ai:propose');
        self::assertCount(2, $this->fake->getRequests());

        $second = $this->runCommand('tsf:gatekeeper:ai:propose');
        self::assertCount(2, $this->fake->getRequests(), 'nothing was sent');
        self::assertStringContainsString('Objects skipped (fresh) 2', preg_replace('/ +/', ' ', $second->getDisplay()) ?? '');

        $forced = $this->runCommand('tsf:gatekeeper:ai:propose', ['--force' => true]);
        self::assertSame(0, $forced->getStatusCode());
        self::assertCount(4, $this->fake->getRequests());
        self::assertCount(3, $this->store->find(), 'rows were replaced, not duplicated');
    }

    public function testInvalidValuesAndDecidedRowsAreHandled(): void
    {
        $product = $this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false);
        $product->save();

        $this->fake->queue(new EnrichmentResponse(
            ['title' => str_repeat('x', 191), 'description' => '  Fine.  '],
            new Usage(10, 10),
            'claude-test',
            EnrichmentResponse::STOP_END_TURN
        ));
        $this->runCommand('tsf:gatekeeper:ai:propose', ['--language' => 'de']);

        $rows = [];
        foreach ($this->store->findByObject($product->getId()) as $row) {
            $rows[$row->getFieldName()] = $row;
        }
        self::assertSame(ProposalStatus::Invalid, $rows['title']->getStatus());
        self::assertSame('191 characters, the field takes at most 190.', $rows['title']->getInvalidReason());
        self::assertSame(ProposalStatus::Pending, $rows['description']->getStatus());
        self::assertSame('Fine.', $rows['description']->getProposedValue());

        $descriptionId = $rows['description']->getId();
        self::assertNotNull($descriptionId);
        $this->store->updateStatus($descriptionId, ProposalStatus::Approved);

        $tester = $this->runCommand('tsf:gatekeeper:ai:propose', ['--language' => 'de', '--force' => true]);
        $display = preg_replace('/ +/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('Proposals pending 1', $display, 'the invalid title got a fresh proposal');
        self::assertStringContainsString('Kept (already decided) 1', $display);
        self::assertSame(ProposalStatus::Approved, $this->store->get($descriptionId)?->getStatus());
    }

    public function testProviderFailures(): void
    {
        $this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false)->save();
        $this->product(['sku' => 'SKU-2', 'name' => 'Plug', 'title' => ['en' => 'Plug']], false)->save();

        $this->fake->queue(
            new EnrichmentResponse([], new Usage(5, 0), 'claude-test', EnrichmentResponse::STOP_REFUSAL, null, 'cyber'),
            ProviderException::fromHttp(429, ['error' => ['message' => 'slow down']])
        );
        $tester = $this->runCommand('tsf:gatekeeper:ai:propose');
        $display = preg_replace('/ +/', ' ', $tester->getDisplay()) ?? '';
        self::assertSame(0, $tester->getStatusCode(), 'a refusal and a retryable failure skip the object, the run goes on');
        self::assertStringContainsString('Objects failed 2', $display);
        self::assertStringContainsString('the model refused (cyber)', $display);
        self::assertStringContainsString('Rate limited', $display);
        self::assertCount(2, $this->store->find(), 'the remaining requests were served by the stand-in');

        $this->fake->reset();
        $this->fake->queue(ProviderException::fromHttp(401, ['error' => ['message' => 'invalid x-api-key']]));
        $tester = $this->runCommand('tsf:gatekeeper:ai:propose', ['--force' => true]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Run stopped: Anthropic API error authentication_error (HTTP 401)', $tester->getDisplay());
        self::assertCount(1, $this->fake->getRequests(), 'a fatal error stops before the next object');
    }

    public function testEstimateAndDryRunStoreNothing(): void
    {
        $this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false)->save();

        $estimate = $this->runCommand('tsf:gatekeeper:ai:propose', ['--estimate' => true]);
        self::assertSame(0, $estimate->getStatusCode());
        self::assertStringContainsString('Estimate for claude-opus-5', $estimate->getDisplay());
        self::assertStringContainsString('Estimated total: $', $estimate->getDisplay());
        self::assertStringContainsString('prefix counted exactly by the API', $estimate->getDisplay());
        self::assertCount(2, $this->fake->getRequests(), 'one token count per group, no generation');
        self::assertSame([], $this->store->find());

        $this->fake->reset();
        $dry = $this->runCommand('tsf:gatekeeper:ai:propose', ['--dry-run' => true]);
        self::assertSame(0, $dry->getStatusCode());
        self::assertStringContainsString('Dry run finished; nothing was stored.', $dry->getDisplay());
        self::assertCount(2, $this->fake->getRequests());
        self::assertSame([], $this->store->find());
    }

    public function testNothingToProposeWhenEverythingPasses(): void
    {
        $this->product($this->complete())->save();

        $tester = $this->runCommand('tsf:gatekeeper:ai:propose');

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to propose.', $tester->getDisplay());
    }

    public function testSnapshotRendersTheFilledFieldsOnly(): void
    {
        $product = $this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'weight' => 2.5, 'title' => ['en' => 'Cable', 'de' => 'Kabel'], 'description' => ['en' => "Two\nlines"]], false);
        $product->save();
        /** @var Concrete $loaded */
        $loaded = Concrete::getById($product->getId());

        /** @var ObjectSnapshot $snapshot */
        $snapshot = $this->service(ObjectSnapshot::class);

        self::assertSame(
            ['sku' => 'SKU-1', 'name' => 'Cable', 'weight' => '2.5', 'title [en]' => 'Cable', 'title [de]' => 'Kabel', 'description [en]' => 'Two lines'],
            $snapshot->filledFields($loaded)
        );
        self::assertSame('Kabel', $snapshot->currentValue($loaded, 'title', 'de'));
        self::assertNull($snapshot->currentValue($loaded, 'title', 'fr'));
        self::assertNull($snapshot->currentValue($loaded, 'seo_title', 'en'));
    }

    /**
     * @return array<string, mixed>
     */
    private function complete(): array
    {
        return ['sku' => 'SKU-9', 'name' => 'Done', 'title' => ['en' => 'Done', 'de' => 'Fertig'], 'description' => ['en' => 'd', 'de' => 'd']];
    }
}
