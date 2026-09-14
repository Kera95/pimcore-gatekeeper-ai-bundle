<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

use function count;

/**
 * The knowledge base as it goes into the prompt: the concatenated Markdown, the files it came
 * from and the hash that ties every proposal to the context that produced it
 */
final class AssembledContext
{
    /**
     * @param string[] $files asset paths relative to the context folder, in the order they were concatenated
     */
    public function __construct(
        private readonly string $text,
        private readonly array $files,
        private readonly int $estimatedTokens,
    ) {
    }

    public function getText(): string
    {
        return $this->text;
    }

    /**
     * @return string[]
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    public function getFileCount(): int
    {
        return count($this->files);
    }

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    public function getChars(): int
    {
        return mb_strlen($this->text);
    }

    public function getEstimatedTokens(): int
    {
        return $this->estimatedTokens;
    }

    /**
     * sha256 of the exact text; the kb_hash of every proposal
     */
    public function getHash(): string
    {
        return hash('sha256', $this->text);
    }
}
