<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Provider\Anthropic;

/**
 * Per-model facts the bundle needs before the first request: the smallest prompt prefix the API
 * caches at all. Prefixes below the floor are processed but never cached, silently.
 */
final class AnthropicModels
{
    /**
     * Minimum cacheable prefix in tokens, matched by model id prefix, longest match first
     */
    private const CACHE_FLOORS = [
        'claude-opus-5' => 512,
        'claude-fable-5' => 512,
        'claude-opus-4-8' => 1024,
        'claude-sonnet-5' => 1024,
        'claude-sonnet-4-6' => 1024,
        'claude-opus-4-7' => 2048,
        'claude-opus-4-6' => 4096,
        'claude-haiku-4-5' => 4096,
    ];

    /**
     * Unknown models get the largest floor, so the warning errs on the side of "may not cache"
     */
    public const DEFAULT_CACHE_FLOOR = 4096;

    public static function cacheFloor(string $model): int
    {
        $best = null;
        foreach (self::CACHE_FLOORS as $prefix => $floor) {
            if (str_starts_with($model, $prefix) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best === null ? self::DEFAULT_CACHE_FLOOR : self::CACHE_FLOORS[$best];
    }

    public static function isKnown(string $model): bool
    {
        foreach (array_keys(self::CACHE_FLOORS) as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
