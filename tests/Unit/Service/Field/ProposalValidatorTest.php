<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Field;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Service\Field\ProposalValidator;

final class ProposalValidatorTest extends Unit
{
    private ProposalValidator $validator;

    protected function _before(): void
    {
        $this->validator = new ProposalValidator();
    }

    public function testInputIsTrimmedFlattenedAndLengthChecked(): void
    {
        $spec = new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', null, true, 20, [], null);

        $result = $this->validator->validate($spec, "  Lenovo LOQ\n 17IRX10  ");
        self::assertTrue($result->isValid());
        self::assertSame('Lenovo LOQ 17IRX10', $result->getValue());

        $tooLong = $this->validator->validate($spec, 'Lenovo LOQ 17IRX10 gaming laptop');
        self::assertFalse($tooLong->isValid());
        self::assertSame('32 characters, the field takes at most 20.', $tooLong->getReason());
        self::assertSame('Lenovo LOQ 17IRX10 gaming laptop', $tooLong->getValue(), 'the offending value is kept for the review');

        self::assertSame('empty value.', $this->validator->validate($spec, "  \n ")->getReason());
        self::assertSame('expected a string, got array.', $this->validator->validate($spec, ['x'])->getReason());
        self::assertSame('expected a string, got null.', $this->validator->validate($spec, null)->getReason());
    }

    public function testTextareaKeepsLineBreaksAndDropsTags(): void
    {
        $spec = new FieldSpec('description', FieldSpec::TYPE_TEXTAREA, 'Description', null, true, null, [], null);

        $result = $this->validator->validate($spec, "Line one.\r\n<b>Line</b> two &amp; three.");

        self::assertTrue($result->isValid());
        self::assertSame("Line one.\nLine two & three.", $result->getValue());
    }

    public function testWysiwygKeepsAllowedTagsOnlyWithoutAttributes(): void
    {
        $spec = new FieldSpec('long_description', FieldSpec::TYPE_WYSIWYG, 'Long', null, true, null, [], null);

        $result = $this->validator->validate($spec, '<h2>Title</h2><p class="x" style="color:red">Intro <strong>bold</strong> <a href="/">link</a></p><ul><li>one</li></ul><script>alert(1)</script>');

        self::assertTrue($result->isValid());
        self::assertSame('Title<p>Intro <strong>bold</strong> link</p><ul><li>one</li></ul>alert(1)', $result->getValue());

        self::assertSame('empty value.', $this->validator->validate($spec, '<p></p><br>')->getReason());
    }

    public function testSelectAcceptsValueOrLabelCaseInsensitivelyAndStoresTheValue(): void
    {
        $spec = new FieldSpec('product_type', FieldSpec::TYPE_SELECT, 'Type', null, false, null, ['simple' => 'Simple product', 10 => 'Ten'], null);

        self::assertSame('simple', $this->validator->validate($spec, 'simple')->getValue());
        self::assertSame('simple', $this->validator->validate($spec, 'SIMPLE PRODUCT')->getValue());
        self::assertSame('10', $this->validator->validate($spec, 10)->getValue());

        $wrong = $this->validator->validate($spec, 'bundle');
        self::assertFalse($wrong->isValid());
        self::assertSame('"bundle" is not one of the allowed values (simple, 10).', $wrong->getReason());
    }

    public function testMultiselectStoresAJsonListOfUniqueValues(): void
    {
        $spec = new FieldSpec('tags', FieldSpec::TYPE_MULTISELECT, 'Tags', null, false, null, ['new' => 'New', 'sale' => 'Sale', 'eco' => 'Eco'], 2);

        $result = $this->validator->validate($spec, ['New', 'sale', ' new ', '']);
        self::assertTrue($result->isValid());
        self::assertSame('["new","sale"]', $result->getValue());

        self::assertSame('["new","eco"]', $this->validator->validate($spec, 'new, eco')->getValue(), 'a comma list is accepted');

        $tooMany = $this->validator->validate($spec, ['new', 'sale', 'eco']);
        self::assertSame('3 values, the field takes at most 2.', $tooMany->getReason());

        self::assertSame('"old" is not one of the allowed values (new, sale, eco).', $this->validator->validate($spec, ['new', 'old'])->getReason());
        self::assertSame('empty list.', $this->validator->validate($spec, [])->getReason());
        self::assertSame('expected a list of values, got null.', $this->validator->validate($spec, null)->getReason());
        self::assertSame('expected a list of strings, got a array inside.', $this->validator->validate($spec, [['x']])->getReason());
    }

    public function testMultibyteLengthCountsCharacters(): void
    {
        $spec = new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', null, true, 9, [], null);

        self::assertTrue($this->validator->validate($spec, 'Kopfhörer')->isValid());
        self::assertFalse($this->validator->validate($spec, 'Kopfhörer!')->isValid());
    }
}
