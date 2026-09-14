<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

/**
 * Token usage of one request as the API reports it; the four numbers are priced separately
 */
final class Usage
{
    public function __construct(
        private readonly int $inputTokens,
        private readonly int $outputTokens,
        private readonly int $cacheReadTokens = 0,
        private readonly int $cacheWriteTokens = 0,
    ) {
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    /**
     * @param array<string, mixed> $usage the "usage" object of a Messages API response
     */
    public static function fromApi(array $usage): self
    {
        return new self(
            (int) ($usage['input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            (int) ($usage['cache_read_input_tokens'] ?? 0),
            (int) ($usage['cache_creation_input_tokens'] ?? 0)
        );
    }

    public function add(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens
        );
    }

    /**
     * Tokens processed at the full input price (the uncached tail)
     */
    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getCacheReadTokens(): int
    {
        return $this->cacheReadTokens;
    }

    public function getCacheWriteTokens(): int
    {
        return $this->cacheWriteTokens;
    }

    /**
     * Everything the model read: uncached, cache reads and cache writes
     */
    public function getTotalInputTokens(): int
    {
        return $this->inputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }
}
