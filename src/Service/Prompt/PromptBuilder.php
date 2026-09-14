<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Prompt;

use Tsf\GatekeeperAiBundle\Model\AssembledContext;
use Tsf\GatekeeperAiBundle\Model\ClassSettings;
use Tsf\GatekeeperAiBundle\Model\EnrichmentRequest;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Service\Field\FieldDescriber;
use Tsf\GatekeeperAiBundle\Service\Field\SchemaBuilder;

use function count;
use function sprintf;

/**
 * Assembles the prompt in the layout the cache depends on:
 *
 *   system[0]  the fixed instructions (PROMPT_VERSION stamped)
 *   system[1]  "# Knowledge base" + the assembled Markdown + the class instructions
 *   system[2]  "# Fields to produce (language: x)" + one line per field   <- cache breakpoint
 *   user       the object's filled fields and the list of fields to produce
 *
 * Everything in system[] is identical for every object of one (class, language) group, so
 * the group's requests share one cached prefix. Bump PROMPT_VERSION when the wording changes;
 * it is stored with every proposal.
 */
final class PromptBuilder
{
    public const PROMPT_VERSION = '2';

    public function __construct(
        private readonly FieldDescriber $describer,
        private readonly SchemaBuilder $schemaBuilder,
    ) {
    }

    /**
     * @param FieldSpec[] $specs
     *
     * @return string[] the three system blocks
     */
    public function prefix(ClassSettings $class, AssembledContext $context, array $specs, string $language): array
    {
        return [
            $this->instructions(),
            $this->knowledgeBlock($class, $context),
            $this->describer->describe($specs, $language),
        ];
    }

    /**
     * @param array<string, string> $filled    field path => value of the fields that have one, in a stable order
     * @param FieldSpec[]           $specs     the fields to produce
     */
    public function userMessage(string $className, string $language, array $filled, array $specs): string
    {
        $lines = [sprintf('Object of class %s%s.', $className, $language !== '' ? ' (language: ' . $language . ')' : '')];

        if (count($filled) > 0) {
            $lines[] = '';
            $lines[] = 'Filled fields:';
            foreach ($filled as $path => $value) {
                $lines[] = sprintf('- %s: %s', $path, $value);
            }
        } else {
            $lines[] = '';
            $lines[] = 'No fields are filled yet.';
        }

        $lines[] = '';
        $lines[] = 'Produce: ' . implode(', ', array_map(static fn (FieldSpec $spec): string => $spec->getPath(), $specs));

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param string[]    $prefix
     * @param FieldSpec[] $specs
     */
    public function request(array $prefix, string $userMessage, array $specs): EnrichmentRequest
    {
        return new EnrichmentRequest($prefix, $userMessage, $this->schemaBuilder->build($specs));
    }

    public function instructions(): string
    {
        return <<<TEXT
        # Role

        You write product data for a Pimcore PIM. You receive a knowledge base about the company and its products, a description of the fields to fill, and one object at a time with the fields it already has. You return the missing fields as JSON.

        # Rules

        1. Use only facts from the object's filled fields and from the knowledge base. Never invent specifications, numbers, materials, compatibility, certifications or claims. If a fact is not given, leave it out rather than guessing.
        2. Write in the language named in the field description, natively - not as a translation. Keep brand names, model numbers, standards and units exactly as given.
        3. Respect every field's type and limits. A value that does not fit is discarded.
        4. Do not repeat the same sentence across fields; each field has its own purpose, described below.
        5. No prices, stock, delivery promises or shop names inside product copy unless the field description asks for them.
        6. Return only the JSON object. The schema lists every field of this group; fill the ones named under "Produce" and return an empty string (or an empty list) for the others.

        (prompt version {$this->version()})
        TEXT . "\n";
    }

    public function knowledgeBlock(ClassSettings $class, AssembledContext $context): string
    {
        $text = "# Knowledge base\n\n" . ($context->isEmpty() ? "(no knowledge base configured)\n" : $context->getText());

        if (trim($class->getInstructions()) !== '') {
            $text .= sprintf("\n# Instructions for %s\n\n%s\n", $class->getClassName(), trim($class->getInstructions()));
        }

        return $text;
    }

    private function version(): string
    {
        return self::PROMPT_VERSION;
    }
}
