<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Functional;

use Tsf\GatekeeperAiBundle\Service\ProposalStore;
use Tsf\GatekeeperAiBundle\Tests\Support\FunctionalTestCase;

final class DataObjectListenerTest extends FunctionalTestCase
{
    public function testDeletingAnObjectRemovesItsProposals(): void
    {
        $product = $this->product(['sku' => 'SKU-1', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false);
        $product->save();
        $other = $this->product(['sku' => 'SKU-2', 'name' => 'Plug', 'title' => ['en' => 'Plug']], false);
        $other->save();
        $this->runCommand('tsf:gatekeeper:ai:propose');

        /** @var ProposalStore $store */
        $store = $this->service(ProposalStore::class);
        self::assertCount(3, $store->findByObject($product->getId()));

        $product->delete();

        self::assertSame([], $store->findByObject($product->getId()));
        self::assertCount(3, $store->findByObject($other->getId()), 'other objects keep theirs');
    }
}
