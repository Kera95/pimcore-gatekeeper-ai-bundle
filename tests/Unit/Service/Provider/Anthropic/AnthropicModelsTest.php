<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Provider\Anthropic;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Service\Provider\Anthropic\AnthropicModels;

final class AnthropicModelsTest extends Unit
{
    /**
     * @dataProvider floors
     */
    public function testCacheFloorByModelPrefix(string $model, int $floor, bool $known): void
    {
        self::assertSame($floor, AnthropicModels::cacheFloor($model));
        self::assertSame($known, AnthropicModels::isKnown($model));
    }

    /**
     * @return iterable<string, array{0: string, 1: int, 2: bool}>
     */
    public static function floors(): iterable
    {
        yield 'opus 5' => ['claude-opus-5', 512, true];
        yield 'opus 5 dated' => ['claude-opus-5-20260601', 512, true];
        yield 'fable 5.1' => ['claude-fable-5-1', 512, true];
        yield 'sonnet 5' => ['claude-sonnet-5', 1024, true];
        yield 'opus 4.8' => ['claude-opus-4-8', 1024, true];
        yield 'opus 4.7' => ['claude-opus-4-7', 2048, true];
        yield 'opus 4.6' => ['claude-opus-4-6', 4096, true];
        yield 'haiku 4.5' => ['claude-haiku-4-5-20251001', 4096, true];
        yield 'unknown' => ['claude-next', AnthropicModels::DEFAULT_CACHE_FLOOR, false];
    }
}
