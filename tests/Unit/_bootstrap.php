<?php

declare(strict_types=1);

use Tsf\GatekeeperAiBundle\Tests\Support\PimcoreStubKernel;

// The unit suite never boots a real Pimcore kernel. A stub is enough for the few core helpers
// (Pimcore\Model\Element\Service) that reach for the container to dispatch their events. When the
// functional suite ran first in the same process, the real kernel is kept.
PimcoreStubKernel::register();
