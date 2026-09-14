<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Field;

use Tsf\GatekeeperAiBundle\Model\FieldSpec;

use function sprintf;

/**
 * The human-readable part of the prompt that tells the model what each field is and what fits
 * into it: type, length, allowed values, the title and tooltip the class definition carries.
 * Byte-stable for the same fields and language, so it can sit inside the cached prefix.
 */
final class FieldDescriber
{
    public const HTML_TAGS = ['p', 'ul', 'ol', 'li', 'strong', 'em', 'br'];

    /**
     * @param FieldSpec[] $specs
     */
    public function describe(array $specs, string $language): string
    {
        $lines = [
            $language === '' ? '# Fields to produce' : sprintf('# Fields to produce (language: %s)', $language),
            '',
            'Return exactly one value per field, as JSON, keyed by the field name. Respect the type and the limits; a value that does not fit is discarded.',
            '',
        ];

        foreach ($specs as $spec) {
            $lines[] = sprintf('- %s: "%s" — %s', $spec->getPath(), $spec->getTitle(), $this->constraints($spec));
        }

        return implode("\n", $lines) . "\n";
    }

    public function constraints(FieldSpec $spec): string
    {
        $parts = [];
        switch ($spec->getType()) {
            case FieldSpec::TYPE_INPUT:
                $parts[] = 'single-line text, no line breaks';
                $parts[] = $this->length($spec);
                break;
            case FieldSpec::TYPE_TEXTAREA:
                $parts[] = 'plain text, line breaks allowed, no HTML';
                $parts[] = $this->length($spec);
                break;
            case FieldSpec::TYPE_WYSIWYG:
                $parts[] = 'HTML using only <' . implode('>, <', self::HTML_TAGS) . '>; no headings, links, images or inline styles';
                break;
            case FieldSpec::TYPE_SELECT:
                $parts[] = 'exactly one of: ' . $this->optionList($spec);
                break;
            case FieldSpec::TYPE_MULTISELECT:
                $parts[] = sprintf('a list of %s values from: %s', $spec->getMaxItems() !== null ? 'up to ' . $spec->getMaxItems() : 'one or more', $this->optionList($spec));
                break;
            default:
                $parts[] = $spec->getType();
        }

        $text = implode(', ', array_filter($parts)) . '.';
        if ($spec->getTooltip() !== null) {
            $text .= ' ' . rtrim($spec->getTooltip(), '.') . '.';
        }

        return $text;
    }

    private function length(FieldSpec $spec): string
    {
        return $spec->getMaxLength() === null ? '' : sprintf('at most %d characters', $spec->getMaxLength());
    }

    private function optionList(FieldSpec $spec): string
    {
        $items = [];
        foreach ($spec->getOptions() as $value => $label) {
            $value = (string) $value;
            $items[] = $label === $value ? sprintf('"%s"', $value) : sprintf('"%s" (%s)', $value, $label);
        }

        return implode(', ', $items);
    }
}
