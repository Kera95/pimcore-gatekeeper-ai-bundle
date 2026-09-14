<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Config;

use Codeception\Test\Unit;
use Symfony\Component\Config\Definition\Processor;
use Tsf\GatekeeperAiBundle\DependencyInjection\Configuration;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;

final class SettingsTest extends Unit
{
    public function testExposesTheProcessedConfiguration(): void
    {
        $settings = $this->settings([
            'anthropic' => ['api_key' => ' sk-test ', 'model' => 'claude-sonnet-5'],
            'fields' => ['deny' => ['SKU', 'ean', 'sku']],
            'classes' => ['Product' => ['enrich' => ['title', 'description'], 'languages' => ['de'], 'instructions' => 'Be brief.']],
        ]);

        self::assertSame('anthropic', $settings->getProvider());
        self::assertSame('sk-test', $settings->getApiKey());
        self::assertSame('claude-sonnet-5', $settings->getModel());
        self::assertSame(2048, $settings->getAnthropic()['max_tokens']);
        self::assertSame(Configuration::DEFAULT_ASSET_FOLDER, $settings->getContextFolder());
        self::assertSame(20000, $settings->getContextMaxTokens());
        self::assertSame(200, $settings->getMaxObjectsPerRun());
        self::assertSame(10.0, $settings->getMaxCostPerRun());
        self::assertSame(150, $settings->getAvgOutputTokensPerField());
        self::assertSame(['sku', 'ean'], $settings->getDenyFields());

        $product = $settings->getClass('Product');
        self::assertNotNull($product);
        self::assertSame('Product', $product->getClassName());
        self::assertSame(['title', 'description'], $product->getEnrich());
        self::assertSame(['de'], $product->getLanguages());
        self::assertSame('Be brief.', $product->getInstructions());
        self::assertSame(['Product'], array_keys($settings->getClasses()));
        self::assertNull($settings->getClass('Category'));
    }

    public function testAnEmptyOrMissingApiKeyIsNull(): void
    {
        self::assertNull($this->settings(['anthropic' => ['api_key' => '']])->getApiKey());
        self::assertNull($this->settings(['anthropic' => ['api_key' => null]])->getApiKey());
    }

    public function testPricingFallsBackToTheBuiltInTable(): void
    {
        $settings = $this->settings(['pricing' => ['claude-opus-5' => ['input' => 1, 'output' => 2, 'cache_write' => 3, 'cache_read' => 4]]]);

        self::assertSame(['input' => 1.0, 'output' => 2.0, 'cache_write' => 3.0, 'cache_read' => 4.0], $settings->getPricing(), 'the configured rate overrides the built-in one');
        self::assertSame(Configuration::DEFAULT_PRICING['claude-sonnet-5'], $settings->getPricing('claude-sonnet-5'));
        self::assertNull($settings->getPricing('claude-unknown'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function settings(array $config): Settings
    {
        return new Settings((new Processor())->processConfiguration(new Configuration(), [$config]));
    }
}
