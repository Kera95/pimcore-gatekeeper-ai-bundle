<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Field;

use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Model\ValidationResult;

use function count;
use function in_array;
use function is_array;
use function is_scalar;
use function sprintf;

/**
 * Checks a value the model returned against the field definition before it becomes a proposal:
 * type, emptiness, length, allowed values, allowed HTML. The schema guarantees the shape of the
 * response, this guarantees the business rules. Returns the normalised value to store.
 */
final class ProposalValidator
{
    /**
     * An opening or closing HTML tag: a "<" followed by a tag name, then anything up to ">"
     */
    private const TAG_PATTERN = '/<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^<>]*>/';

    public function validate(FieldSpec $spec, mixed $value): ValidationResult
    {
        if ($spec->isMultiValued()) {
            return $this->validateList($spec, $value);
        }

        if (!is_scalar($value)) {
            return ValidationResult::invalid(sprintf('expected a string, got %s.', get_debug_type($value)));
        }

        $text = $this->normalise((string) $value, $spec);
        if ($text === '') {
            return ValidationResult::invalid('empty value.');
        }

        if ($spec->hasOptions()) {
            $matched = $this->matchOption($spec, $text);
            if ($matched === null) {
                return ValidationResult::invalid(sprintf('"%s" is not one of the allowed values (%s).', $this->excerpt($text), implode(', ', $spec->getOptionValues())), $text);
            }

            return ValidationResult::valid($matched);
        }

        $length = mb_strlen($text);
        if ($spec->getMaxLength() !== null && $length > $spec->getMaxLength()) {
            return ValidationResult::invalid(sprintf('%d characters, the field takes at most %d.', $length, $spec->getMaxLength()), $text);
        }

        return ValidationResult::valid($text);
    }

    private function validateList(FieldSpec $spec, mixed $value): ValidationResult
    {
        if (is_scalar($value)) {
            $value = array_map('trim', explode(',', (string) $value));
        }
        if (!is_array($value)) {
            return ValidationResult::invalid(sprintf('expected a list of values, got %s.', get_debug_type($value)));
        }

        $values = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                return ValidationResult::invalid(sprintf('expected a list of strings, got a %s inside.', get_debug_type($item)));
            }
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $matched = $this->matchOption($spec, $text);
            if ($matched === null) {
                return ValidationResult::invalid(sprintf('"%s" is not one of the allowed values (%s).', $this->excerpt($text), implode(', ', $spec->getOptionValues())), json_encode(array_values($value)) ?: '');
            }
            if (!in_array($matched, $values, true)) {
                $values[] = $matched;
            }
        }

        if (count($values) === 0) {
            return ValidationResult::invalid('empty list.');
        }
        if ($spec->getMaxItems() !== null && count($values) > $spec->getMaxItems()) {
            return ValidationResult::invalid(sprintf('%d values, the field takes at most %d.', count($values), $spec->getMaxItems()), json_encode($values) ?: '');
        }

        return ValidationResult::valid(json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]');
    }

    /**
     * Trims, unifies line endings, strips what the field cannot hold: line breaks in inputs,
     * every tag in plain text fields, tags outside the allow-list in HTML fields.
     *
     * Tags are matched by a pattern rather than strip_tags(), which treats any "<" followed by a
     * non-space character as the start of a tag and silently drops the rest of the text
     * ("screens <15 inches" would become "screens ").
     */
    private function normalise(string $text, FieldSpec $spec): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;

        if ($spec->allowsHtml()) {
            // keep the allowed tags without their attributes, drop every other tag
            $text = preg_replace_callback(
                self::TAG_PATTERN,
                static fn (array $m): string => in_array(strtolower($m[2]), FieldDescriber::HTML_TAGS, true) ? '<' . $m[1] . strtolower($m[2]) . '>' : '',
                $text
            ) ?? $text;
            $text = trim($text);

            return trim($this->stripTags($text)) === '' ? '' : $text;
        }

        $text = html_entity_decode($this->stripTags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($spec->getType() === FieldSpec::TYPE_INPUT || $spec->hasOptions()) {
            $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        }

        return trim($text);
    }

    private function stripTags(string $text): string
    {
        return preg_replace(self::TAG_PATTERN, '', $text) ?? $text;
    }

    /**
     * The option value the text denotes: the value itself, or its label, case-insensitively
     */
    private function matchOption(FieldSpec $spec, string $text): ?string
    {
        foreach ($spec->getOptions() as $value => $label) {
            $value = (string) $value;
            if (strcasecmp($value, $text) === 0 || strcasecmp($label, $text) === 0) {
                return $value;
            }
        }

        return null;
    }

    private function excerpt(string $text): string
    {
        return mb_strlen($text) > 40 ? mb_substr($text, 0, 37) . '...' : $text;
    }
}
