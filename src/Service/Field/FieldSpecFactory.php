<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Field;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperBundle\Service\FieldReader;

/**
 * Turns a field path of a class into a FieldSpec, or null when the path does not resolve
 */
final class FieldSpecFactory
{
    public function __construct(
        private readonly FieldReader $fieldReader,
    ) {
    }

    public function create(ClassDefinition $class, string $path): ?FieldSpec
    {
        $definition = $this->definition($class, $path);
        if ($definition === null) {
            return null;
        }

        return FieldSpec::fromDefinition($path, $definition, $this->fieldReader->isLocalized($class, $path));
    }

    /**
     * The Pimcore definition behind a path, for callers that also need to ask the FieldPolicy
     */
    public function definition(ClassDefinition $class, string $path): ?Data
    {
        return $this->fieldReader->getDefinition($class, $path);
    }
}
