<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Field;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Service\Field\FieldDescriber;

final class FieldDescriberTest extends Unit
{
    public function testDescribesEveryTypeWithItsConstraints(): void
    {
        $text = (new FieldDescriber())->describe([
            new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', 'Shown in listings.', true, 80, [], null),
            new FieldSpec('description', FieldSpec::TYPE_TEXTAREA, 'Description', null, true, null, [], null),
            new FieldSpec('long_description', FieldSpec::TYPE_WYSIWYG, 'Long description', null, true, null, [], null),
            new FieldSpec('product_type', FieldSpec::TYPE_SELECT, 'Product type', null, false, null, ['simple' => 'Simple', 'configurable' => 'configurable'], null),
            new FieldSpec('tags', FieldSpec::TYPE_MULTISELECT, 'Tags', null, false, null, ['new' => 'New', 'sale' => 'Sale'], 3),
        ], 'de');

        self::assertSame(
            "# Fields to produce (language: de)\n"
            . "\n"
            . "Return exactly one value per field, as JSON, keyed by the field name. Respect the type and the limits; a value that does not fit is discarded.\n"
            . "\n"
            . "- title: \"Title\" — single-line text, no line breaks, at most 80 characters. Shown in listings.\n"
            . "- description: \"Description\" — plain text, line breaks allowed, no HTML.\n"
            . "- long_description: \"Long description\" — HTML using only <p>, <ul>, <ol>, <li>, <strong>, <em>, <br>; no headings, links, images or inline styles.\n"
            . "- product_type: \"Product type\" — exactly one of: \"simple\" (Simple), \"configurable\".\n"
            . "- tags: \"Tags\" — a list of up to 3 values from: \"new\" (New), \"sale\" (Sale).\n",
            $text
        );
    }

    public function testNoLanguageHeadingForNonLocalizedGroups(): void
    {
        $text = (new FieldDescriber())->describe([new FieldSpec('name', FieldSpec::TYPE_INPUT, 'Name', null, false, 190, [], null)], '');

        self::assertStringStartsWith("# Fields to produce\n", $text);
        self::assertStringContainsString('- name: "Name" — single-line text, no line breaks, at most 190 characters.', $text);
    }

    public function testIsByteStable(): void
    {
        $specs = [new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', null, true, 80, [], null)];

        self::assertSame((new FieldDescriber())->describe($specs, 'en'), (new FieldDescriber())->describe($specs, 'en'));
    }
}
