<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Provider;

use Codeception\Test\Unit;
use Symfony\Component\Config\Definition\Processor;
use Tsf\GatekeeperAiBundle\DependencyInjection\Configuration;
use Tsf\GatekeeperAiBundle\Model\Usage;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Provider\Pricing;

final class PricingTest extends Unit
{
    public function testPricesTheFourTokenKindsSeparately(): void
    {
        $pricing = new Pricing(new Settings((new Processor())->processConfiguration(new Configuration(), [[]])));

        // opus 5: 5 / 25 / 6.25 write / 0.5 read per MTok
        $cost = $pricing->cost(new Usage(1_000_000, 100_000, 2_000_000, 400_000));

        self::assertEqualsWithDelta(5.0 + 2.5 + 1.0 + 2.5, $cost, 0.000001);
        self::assertTrue($pricing->isKnown());
        self::assertSame(0.0, $pricing->cost(new Usage(10, 10), 'claude-unknown'));
        self::assertFalse($pricing->isKnown('claude-unknown'));
    }

    public function testUsageAddsAndTotals(): void
    {
        $usage = Usage::fromApi(['input_tokens' => 10, 'output_tokens' => 20, 'cache_read_input_tokens' => 30, 'cache_creation_input_tokens' => 40])
            ->add(new Usage(1, 2, 3, 4));

        self::assertSame([11, 22, 33, 44], [$usage->getInputTokens(), $usage->getOutputTokens(), $usage->getCacheReadTokens(), $usage->getCacheWriteTokens()]);
        self::assertSame(88, $usage->getTotalInputTokens());
        self::assertSame(0, Usage::none()->getTotalInputTokens());
    }
}
