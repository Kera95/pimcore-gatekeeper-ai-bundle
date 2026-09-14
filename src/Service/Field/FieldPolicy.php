<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Field;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperBundle\Service\FieldReader;

use function in_array;
use function sprintf;

/**
 * Which fields the model may write at all: text-like data types only, and never a field whose
 * name is on the deny list (identifiers, prices). Everything else is skipped, whatever the class
 * configuration says.
 */
final class FieldPolicy
{
    public const ALLOWED_TYPES = ['input', 'textarea', 'wysiwyg', 'select', 'multiselect'];

    public function __construct(
        private readonly Settings $settings,
    ) {
    }

    /**
     * Why the field must not be proposed, or null when it may
     */
    public function describeProblem(string $fieldPath, Data $definition): ?string
    {
        $name = $fieldPath;
        if (str_contains($fieldPath, FieldReader::PATH_SEPARATOR)) {
            $parts = explode(FieldReader::PATH_SEPARATOR, $fieldPath);
            $name = (string) end($parts);
        }

        if (in_array(strtolower($name), $this->settings->getDenyFields(), true)) {
            return sprintf('"%s" is on the deny list (tsf_gatekeeper_ai.fields.deny).', $fieldPath);
        }

        $type = $definition->getFieldType();
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return sprintf('"%s" is of type %s; only %s can be proposed.', $fieldPath, $type, implode(', ', self::ALLOWED_TYPES));
        }

        return null;
    }

    public function isEnrichable(string $fieldPath, Data $definition): bool
    {
        return $this->describeProblem($fieldPath, $definition) === null;
    }
}
