<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

/**
 * Enrichment settings of one DataObject class, from tsf_gatekeeper_ai.classes
 */
final class ClassSettings
{
    /**
     * @param string[] $enrich    field paths the model may fill
     * @param string[] $languages empty = the languages of the Gatekeeper rule
     */
    public function __construct(
        private readonly string $className,
        private readonly array $enrich,
        private readonly array $languages,
        private readonly string $instructions,
    ) {
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @return string[]
     */
    public function getEnrich(): array
    {
        return $this->enrich;
    }

    /**
     * @return string[]
     */
    public function getLanguages(): array
    {
        return $this->languages;
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }
}
