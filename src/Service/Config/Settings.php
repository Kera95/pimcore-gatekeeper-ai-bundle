<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Config;

use Tsf\GatekeeperAiBundle\DependencyInjection\Configuration;
use Tsf\GatekeeperAiBundle\Model\ClassSettings;

use function is_string;

/**
 * Typed view of the processed tsf_gatekeeper_ai configuration
 */
final class Settings
{
    /**
     * @var array<string, ClassSettings>
     */
    private array $classes = [];

    /**
     * @param array<string, mixed> $config the processed configuration
     */
    public function __construct(
        private readonly array $config,
    ) {
        foreach ($config['classes'] ?? [] as $className => $class) {
            $this->classes[$className] = new ClassSettings(
                (string) $className,
                array_values($class['enrich']),
                array_values($class['languages'] ?? []),
                (string) ($class['instructions'] ?? '')
            );
        }
    }

    public function getProvider(): string
    {
        return (string) $this->config['provider'];
    }

    /**
     * Null when no key is configured. Never log or print the value.
     */
    public function getApiKey(): ?string
    {
        $key = $this->config['anthropic']['api_key'] ?? null;

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    public function getModel(): string
    {
        return (string) $this->config['anthropic']['model'];
    }

    /**
     * @return array{api_key: string|null, model: string, max_tokens: int, effort: string, prompt_caching: bool, cache_ttl: string, timeout: int, max_retries: int}
     */
    public function getAnthropic(): array
    {
        return $this->config['anthropic'];
    }

    /**
     * USD per million tokens of the model, from the configuration or the built-in table; null when
     * neither knows the model.
     *
     * @return array{input: float, output: float, cache_write: float, cache_read: float}|null
     */
    public function getPricing(?string $model = null): ?array
    {
        $model ??= $this->getModel();
        $rates = $this->config['pricing'][$model] ?? Configuration::DEFAULT_PRICING[$model] ?? null;
        if ($rates === null) {
            return null;
        }

        return [
            'input' => (float) $rates['input'],
            'output' => (float) $rates['output'],
            'cache_write' => (float) $rates['cache_write'],
            'cache_read' => (float) $rates['cache_read'],
        ];
    }

    public function getContextFolder(): string
    {
        return (string) $this->config['context']['asset_folder'];
    }

    public function getContextMaxTokens(): int
    {
        return (int) $this->config['context']['max_tokens'];
    }

    public function getMaxObjectsPerRun(): int
    {
        return (int) $this->config['limits']['max_objects_per_run'];
    }

    public function getMaxCostPerRun(): float
    {
        return (float) $this->config['limits']['max_cost_per_run'];
    }

    public function getAvgOutputTokensPerField(): int
    {
        return (int) $this->config['limits']['avg_output_tokens_per_field'];
    }

    /**
     * @return string[] lower-cased
     */
    public function getDenyFields(): array
    {
        return array_values(array_unique(array_map(
            static fn (string $field): string => strtolower($field),
            $this->config['fields']['deny'] ?? []
        )));
    }

    /**
     * @return array<string, ClassSettings> keyed by class name
     */
    public function getClasses(): array
    {
        return $this->classes;
    }

    public function getClass(string $className): ?ClassSettings
    {
        return $this->classes[$className] ?? null;
    }
}
