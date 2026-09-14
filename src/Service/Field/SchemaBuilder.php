<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Field;

use Tsf\GatekeeperAiBundle\Model\FieldSpec;

/**
 * The JSON schema the response is forced into: one required property per field, strings or
 * enums, arrays of enums for multiselects, nothing else. Length and item limits are not part of
 * the schema because the API rejects them; they are stated in the property description and
 * checked by the ProposalValidator.
 */
final class SchemaBuilder
{
    public function __construct(
        private readonly FieldDescriber $describer,
    ) {
    }

    /**
     * @param FieldSpec[] $specs
     *
     * @return array<string, mixed>
     */
    public function build(array $specs): array
    {
        $properties = [];
        $required = [];
        foreach ($specs as $spec) {
            $properties[$spec->getPath()] = $this->property($spec);
            $required[] = $spec->getPath();
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function property(FieldSpec $spec): array
    {
        $description = $spec->getTitle() . ': ' . $this->describer->constraints($spec);

        if ($spec->isMultiValued()) {
            return [
                'type' => 'array',
                'items' => ['type' => 'string', 'enum' => $spec->getOptionValues()],
                'description' => $description,
            ];
        }

        $property = ['type' => 'string', 'description' => $description];
        if ($spec->getType() === FieldSpec::TYPE_SELECT) {
            $property['enum'] = $spec->getOptionValues();
        }

        return $property;
    }
}
