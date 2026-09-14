<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

/**
 * One generation request: the static prefix blocks (instructions, knowledge base, field
 * descriptions - identical for every object of a group, so the provider can cache them), the
 * per-object user message and the schema the answer must match.
 */
final class EnrichmentRequest
{
    /**
     * @param string[]             $prefixBlocks system blocks in order; the last one carries the cache breakpoint
     * @param array<string, mixed> $schema       JSON schema of the answer
     */
    public function __construct(
        private readonly array $prefixBlocks,
        private readonly string $userMessage,
        private readonly array $schema,
    ) {
    }

    /**
     * @return string[]
     */
    public function getPrefixBlocks(): array
    {
        return $this->prefixBlocks;
    }

    public function getUserMessage(): string
    {
        return $this->userMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * sha256 over the exact prefix bytes; a cache miss between two requests with the same hash
     * is not caused by the prompt
     */
    public function getPrefixHash(): string
    {
        return hash('sha256', implode("\n\x00\n", $this->prefixBlocks));
    }

    public function getPrefixText(): string
    {
        return implode("\n\n", $this->prefixBlocks);
    }
}
