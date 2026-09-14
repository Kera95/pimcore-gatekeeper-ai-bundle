<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Model;

use Pimcore\Model\DataObject\ClassDefinition\Data;

use function count;
use function is_array;

/**
 * What the describer, the schema builder and the validator all need to know about one field:
 * the parts of the Pimcore definition that constrain a value, read once, no Pimcore objects kept.
 */
final class FieldSpec
{
    public const TYPE_INPUT = 'input';

    public const TYPE_TEXTAREA = 'textarea';

    public const TYPE_WYSIWYG = 'wysiwyg';

    public const TYPE_SELECT = 'select';

    public const TYPE_MULTISELECT = 'multiselect';

    /**
     * @param array<int|string, string> $options value => label, in definition order (PHP turns numeric values into int keys); empty for text fields
     */
    public function __construct(
        private readonly string $path,
        private readonly string $type,
        private readonly string $title,
        private readonly ?string $tooltip,
        private readonly bool $localized,
        private readonly ?int $maxLength,
        private readonly array $options,
        private readonly ?int $maxItems,
    ) {
    }

    public static function fromDefinition(string $path, Data $definition, bool $localized): self
    {
        $type = $definition->getFieldType();
        $maxLength = null;
        $options = [];
        $maxItems = null;

        if ($definition instanceof Data\Input) {
            $maxLength = $definition->getColumnLength();
        } elseif ($definition instanceof Data\Textarea) {
            $maxLength = $definition->getMaxLength();
        } elseif ($definition instanceof Data\Select || $definition instanceof Data\Multiselect) {
            foreach ($definition->getOptions() ?? [] as $option) {
                // the empty option ("none") is not a value to propose
                if (is_array($option) && isset($option['value']) && (string) $option['value'] !== '') {
                    $options[(string) $option['value']] = (string) ($option['key'] ?? $option['value']);
                }
            }
            if ($definition instanceof Data\Multiselect) {
                $maxItems = $definition->getMaxItems();
            }
        }

        $title = trim($definition->getTitle());
        $tooltip = trim((string) $definition->getTooltip());

        return new self(
            $path,
            $type,
            $title !== '' ? $title : ($definition->getName() ?? $path),
            $tooltip !== '' ? $tooltip : null,
            $localized,
            $maxLength !== null && $maxLength > 0 ? $maxLength : null,
            $options,
            $maxItems !== null && $maxItems > 0 ? $maxItems : null
        );
    }

    /**
     * The field path in the Gatekeeper syntax; also the key in the JSON the model returns
     */
    public function getPath(): string
    {
        return $this->path;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getTooltip(): ?string
    {
        return $this->tooltip;
    }

    public function isLocalized(): bool
    {
        return $this->localized;
    }

    public function getMaxLength(): ?int
    {
        return $this->maxLength;
    }

    /**
     * @return array<int|string, string> value => label
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return string[] PHP turns numeric option values into int keys; this gives them back as strings
     */
    public function getOptionValues(): array
    {
        return array_map('strval', array_keys($this->options));
    }

    public function hasOptions(): bool
    {
        return count($this->options) > 0;
    }

    public function getMaxItems(): ?int
    {
        return $this->maxItems;
    }

    public function isMultiValued(): bool
    {
        return $this->type === self::TYPE_MULTISELECT;
    }

    public function allowsHtml(): bool
    {
        return $this->type === self::TYPE_WYSIWYG;
    }
}
