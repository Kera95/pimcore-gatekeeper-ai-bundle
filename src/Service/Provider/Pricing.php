<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Provider;

use Tsf\GatekeeperAiBundle\Model\Usage;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;

/**
 * USD for a usage at the configured model's rates (per million tokens, four separate prices)
 */
final class Pricing
{
    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    public function cost(Usage $usage, ?string $model = null): float
    {
        $rates = $this->settings->getPricing($model);
        if ($rates === null) {
            return 0.0;
        }

        return (
            $usage->getInputTokens() * $rates['input']
            + $usage->getOutputTokens() * $rates['output']
            + $usage->getCacheReadTokens() * $rates['cache_read']
            + $usage->getCacheWriteTokens() * $rates['cache_write']
        ) / 1_000_000;
    }

    public function isKnown(?string $model = null): bool
    {
        return $this->settings->getPricing($model) !== null;
    }
}
