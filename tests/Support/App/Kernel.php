<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Support\App;

use Pimcore\HttpKernel\BundleCollection\BundleCollection;
use Pimcore\Kernel as PimcoreKernel;
use Tsf\GatekeeperAiBundle\TsfGatekeeperAiBundle;
use Tsf\GatekeeperBundle\TsfGatekeeperBundle;

/**
 * Kernel of the throwaway project the functional suite runs in: Pimcore core, the Gatekeeper
 * bundle and this bundle, configured by config/packages/gatekeeper_ai_test.yaml next to this file.
 */
final class Kernel extends PimcoreKernel
{
    public function registerBundlesToCollection(BundleCollection $collection): void
    {
        $collection->addBundle(new TsfGatekeeperBundle());
        $collection->addBundle(new TsfGatekeeperAiBundle());
    }
}
