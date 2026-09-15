<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\DependencyInjection;

use Codeception\Test\Unit;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Tsf\GatekeeperAiBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends Unit
{
    public function testEmptyConfigurationYieldsTheDocumentedDefaults(): void
    {
        self::assertSame(
            [
                'provider' => 'anthropic',
                'anthropic' => [
                    'api_key' => '%env(default::ANTHROPIC_API_KEY)%',
                    'model' => Configuration::DEFAULT_MODEL,
                    'max_tokens' => 2048,
                    'effort' => 'low',
                    'prompt_caching' => true,
                    'cache_ttl' => '5m',
                    'timeout' => 60,
                    'max_retries' => 3,
                ],
                'pricing' => [],
                'context' => [
                    'asset_folder' => Configuration::DEFAULT_ASSET_FOLDER,
                    'max_tokens' => 20000,
                ],
                'limits' => [
                    'max_objects_per_run' => 200,
                    'max_cost_per_run' => 10.0,
                    'avg_output_tokens_per_field' => 150,
                ],
                'fields' => [
                    'deny' => Configuration::DEFAULT_DENY_FIELDS,
                ],
                'classes' => [],
            ],
            $this->process([])
        );
    }

    public function testClassDefaultsAreApplied(): void
    {
        $config = $this->process(['classes' => ['Product' => ['enrich' => ['title']]]]);

        self::assertSame(['enrich' => ['title'], 'languages' => [], 'instructions' => ''], $config['classes']['Product']);
    }

    public function testPricingEntriesAreKeyedByModel(): void
    {
        $config = $this->process(['pricing' => ['claude-next' => ['input' => 1, 'output' => 2, 'cache_write' => 1.25, 'cache_read' => 0.1]]]);

        self::assertSame(['input' => 1, 'output' => 2, 'cache_write' => 1.25, 'cache_read' => 0.1], $config['pricing']['claude-next'], 'model ids keep their dashes');
    }

    /**
     * @dataProvider invalidConfigurations
     *
     * @param array<string, mixed> $config
     */
    public function testInvalidConfigurationsAreRejected(array $config, string $messagePart): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messagePart, '/') . '/');

        $this->process($config);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown provider' => [['provider' => 'openai'], 'Permissible values: "anthropic", "fake"'];
        yield 'unknown effort' => [['anthropic' => ['effort' => 'max']], 'Permissible values: "low", "medium", "high"'];
        yield 'unknown cache ttl' => [['anthropic' => ['cache_ttl' => '2h']], 'Permissible values: "5m", "1h"'];
        yield 'empty model' => [['anthropic' => ['model' => '']], 'cannot contain an empty value'];
        yield 'zero max_tokens' => [['anthropic' => ['max_tokens' => 0]], 'Should be greater than or equal to 1'];
        yield 'pricing without output' => [['pricing' => ['m' => ['input' => 1, 'cache_write' => 1, 'cache_read' => 1]]], 'must be configured'];
        yield 'relative asset folder' => [['context' => ['asset_folder' => 'context']], 'absolute asset path'];
        yield 'negative cost ceiling' => [['limits' => ['max_cost_per_run' => -1]], 'Should be greater than or equal to 0'];
        yield 'class without enrich' => [['classes' => ['Product' => []]], 'must be configured'];
        yield 'class with empty enrich' => [['classes' => ['Product' => ['enrich' => []]]], 'should have at least 1 element'];
        yield 'duplicate enrich field' => [['classes' => ['Product' => ['enrich' => ['title', 'title']]]], 'duplicate field names'];
        yield 'bad language' => [['classes' => ['Product' => ['enrich' => ['title'], 'languages' => ['english']]]], 'Invalid language code'];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
