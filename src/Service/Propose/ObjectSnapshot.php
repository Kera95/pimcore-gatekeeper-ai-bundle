<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Propose;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Data\AbstractQuantityValue;
use Pimcore\Model\DataObject\Data\Link;
use Pimcore\Model\Element\ElementInterface;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessChecker;
use Tsf\GatekeeperBundle\Service\FieldReader;
use Tsf\GatekeeperBundle\Service\LanguageProvider;

use function count;
use function is_array;
use function is_bool;
use function is_scalar;

/**
 * The object as the model sees it: every filled top-level and localized field that has a
 * textual reading, as "path: value" pairs in definition order. Localized values carry their
 * language ("title [en]"). Relations contribute the keys of the related elements; images,
 * files, tables, bricks and collections are left out. Values are capped so one object cannot
 * blow up the request. The Gatekeeper's own score field is left out: it says nothing about
 * the product.
 */
final class ObjectSnapshot
{
    public const MAX_VALUE_LENGTH = 1500;

    public function __construct(
        private readonly FieldReader $fieldReader,
        private readonly EmptinessChecker $emptiness,
        private readonly LanguageProvider $languages,
        private readonly RuleSet $rules,
    ) {
    }

    /**
     * @return array<string, string> "path" or "path [lang]" => rendered value
     */
    public function filledFields(Concrete $object): array
    {
        $class = $object->getClass();
        $scoreField = $this->rules->get((string) $class->getName())?->getScoreField();
        $filled = [];

        foreach ($class->getFieldDefinitions() as $definition) {
            if ($definition->getName() === $scoreField) {
                continue;
            }
            if ($definition instanceof Data\Localizedfields) {
                foreach ($definition->getFieldDefinitions() as $localized) {
                    foreach ($this->languages->getValidLanguages() as $language) {
                        $value = $this->render($localized, $this->fieldReader->readAll($object, (string) $localized->getName(), $language)[0] ?? null);
                        if ($value !== null) {
                            $filled[$localized->getName() . ' [' . $language . ']'] = $value;
                        }
                    }
                }

                continue;
            }

            $value = $this->render($definition, $this->fieldReader->readAll($object, (string) $definition->getName(), null)[0] ?? null);
            if ($value !== null) {
                $filled[(string) $definition->getName()] = $value;
            }
        }

        return $filled;
    }

    /**
     * The current raw value of one field as text, for the proposal's current_value column
     */
    public function currentValue(Concrete $object, string $path, string $language): ?string
    {
        $definition = $this->fieldReader->getDefinition($object->getClass(), $path);
        if ($definition === null) {
            return null;
        }
        $value = $this->fieldReader->readAll($object, $path, $language === '' ? null : $language)[0] ?? null;

        return $this->render($definition, $value);
    }

    /**
     * Null when the field is empty or has no textual reading
     */
    private function render(Data $definition, mixed $value): ?string
    {
        if ($value === null || $this->emptiness->isEmpty($definition, $value)) {
            return null;
        }

        $text = match (true) {
            $definition instanceof Data\Wysiwyg => trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            $definition instanceof Data\Checkbox => is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value,
            $definition instanceof Data\Date, $definition instanceof Data\Datetime => $value instanceof \DateTimeInterface ? $value->format($definition instanceof Data\Datetime ? 'Y-m-d H:i' : 'Y-m-d') : null,
            $value instanceof AbstractQuantityValue => trim((string) $value->getValue() . ' ' . ($value->getUnit()?->getAbbreviation() ?? '')),
            $value instanceof Link => trim($value->getText() . ' ' . $value->getHref()),
            $value instanceof ElementInterface => (string) $value->getKey(),
            is_array($value) => $this->renderList($value),
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => null,
        };

        if ($text === null) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > self::MAX_VALUE_LENGTH ? mb_substr($text, 0, self::MAX_VALUE_LENGTH - 1) . '…' : $text;
    }

    /**
     * @param array<int|string, mixed> $values
     */
    private function renderList(array $values): ?string
    {
        $parts = [];
        foreach ($values as $item) {
            if ($item instanceof ElementInterface) {
                $parts[] = (string) $item->getKey();
            } elseif (is_scalar($item) || $item instanceof \Stringable) {
                $parts[] = (string) $item;
            }
        }
        $parts = array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== '');

        return count($parts) > 0 ? implode(', ', $parts) : null;
    }
}
