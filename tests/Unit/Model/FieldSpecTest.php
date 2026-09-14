<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Model;

use Codeception\Test\Unit;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;

final class FieldSpecTest extends Unit
{
    public function testReadsInputLengthTitleAndTooltip(): void
    {
        $input = new Data\Input();
        $input->setName('title');
        $input->setTitle(' Title ');
        $input->setTooltip('Shown in listings.');
        $input->setColumnLength(80);

        $spec = FieldSpec::fromDefinition('title', $input, true);

        self::assertSame('title', $spec->getPath());
        self::assertSame(FieldSpec::TYPE_INPUT, $spec->getType());
        self::assertSame('Title', $spec->getTitle());
        self::assertSame('Shown in listings.', $spec->getTooltip());
        self::assertTrue($spec->isLocalized());
        self::assertSame(80, $spec->getMaxLength());
        self::assertSame([], $spec->getOptions());
        self::assertFalse($spec->hasOptions());
        self::assertFalse($spec->isMultiValued());
        self::assertFalse($spec->allowsHtml());
    }

    public function testFallsBackToTheNameWithoutATitleAndNullsEmptyLimits(): void
    {
        $textarea = new Data\Textarea();
        $textarea->setName('description');
        $textarea->setMaxLength(0);

        $spec = FieldSpec::fromDefinition('description', $textarea, false);

        self::assertSame('description', $spec->getTitle());
        self::assertNull($spec->getTooltip());
        self::assertNull($spec->getMaxLength());
        self::assertFalse($spec->isLocalized());
    }

    public function testWysiwygAllowsHtml(): void
    {
        $wysiwyg = new Data\Wysiwyg();
        $wysiwyg->setName('long_description');

        self::assertTrue(FieldSpec::fromDefinition('long_description', $wysiwyg, true)->allowsHtml());
    }

    public function testReadsSelectOptionsInOrderKeepingNumericValuesAsStrings(): void
    {
        $select = new Data\Multiselect();
        $select->setName('sizes');
        $select->setOptions([['key' => '(none)', 'value' => ''], ['key' => 'Small', 'value' => 's'], ['key' => 'Ten', 'value' => '10'], ['key' => 'Large', 'value' => 'l']]);
        $select->setMaxItems(2);

        $spec = FieldSpec::fromDefinition('sizes', $select, false);

        self::assertSame(['s' => 'Small', 10 => 'Ten', 'l' => 'Large'], $spec->getOptions(), 'the empty option is not a value to propose');
        self::assertSame(['s', '10', 'l'], $spec->getOptionValues());
        self::assertTrue($spec->hasOptions());
        self::assertTrue($spec->isMultiValued());
        self::assertSame(2, $spec->getMaxItems());
    }
}
