<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Field;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Service\Field\FieldDescriber;
use Tsf\GatekeeperAiBundle\Service\Field\SchemaBuilder;

final class SchemaBuilderTest extends Unit
{
    public function testBuildsAClosedObjectWithOnePropertyPerField(): void
    {
        $schema = (new SchemaBuilder(new FieldDescriber()))->build([
            new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', null, true, 80, [], null),
            new FieldSpec('product_type', FieldSpec::TYPE_SELECT, 'Product type', null, false, null, ['simple' => 'Simple', 10 => 'Ten'], null),
            new FieldSpec('tags', FieldSpec::TYPE_MULTISELECT, 'Tags', null, false, null, ['new' => 'New', 'sale' => 'Sale'], 3),
        ]);

        self::assertSame('object', $schema['type']);
        self::assertSame(['title', 'product_type', 'tags'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);

        self::assertSame(['type' => 'string', 'description' => 'Title: single-line text, no line breaks, at most 80 characters.'], $schema['properties']['title']);
        self::assertSame(['type' => 'string', 'description' => 'Product type: exactly one of: "simple" (Simple), "10" (Ten).', 'enum' => ['simple', '10']], $schema['properties']['product_type']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['new', 'sale']], 'description' => 'Tags: a list of up to 3 values from: "new" (New), "sale" (Sale).'], $schema['properties']['tags']);
    }

    public function testNeverEmitsConstraintsTheApiRejects(): void
    {
        $json = json_encode((new SchemaBuilder(new FieldDescriber()))->build([
            new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', null, true, 80, [], null),
            new FieldSpec('tags', FieldSpec::TYPE_MULTISELECT, 'Tags', null, false, null, ['a' => 'A'], 3),
        ]));

        self::assertIsString($json);
        self::assertStringNotContainsString('maxLength', $json);
        self::assertStringNotContainsString('minLength', $json);
        self::assertStringNotContainsString('maxItems', $json);
    }

    public function testNoFieldsIsStillAnObjectSchema(): void
    {
        $json = json_encode((new SchemaBuilder(new FieldDescriber()))->build([]));

        self::assertSame('{"type":"object","properties":{},"required":[],"additionalProperties":false}', $json);
    }
}
