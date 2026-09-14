<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Prompt;

use Codeception\Test\Unit;
use Tsf\GatekeeperAiBundle\Model\AssembledContext;
use Tsf\GatekeeperAiBundle\Model\ClassSettings;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Service\Field\FieldDescriber;
use Tsf\GatekeeperAiBundle\Service\Field\SchemaBuilder;
use Tsf\GatekeeperAiBundle\Service\Prompt\PromptBuilder;

final class PromptBuilderTest extends Unit
{
    private PromptBuilder $builder;

    protected function _before(): void
    {
        $this->builder = new PromptBuilder(new FieldDescriber(), new SchemaBuilder(new FieldDescriber()));
    }

    public function testPrefixHasThreeBlocksInTheCacheOrder(): void
    {
        $class = new ClassSettings('Product', ['title'], ['de'], 'Write for shoppers.');
        $context = new AssembledContext("## tone.md\n\nFriendly.\n", ['tone.md'], 5);
        $specs = [new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', null, true, 80, [], null)];

        $prefix = $this->builder->prefix($class, $context, $specs, 'de');

        self::assertCount(3, $prefix);
        self::assertStringStartsWith("# Role\n", $prefix[0]);
        self::assertStringContainsString('(prompt version ' . PromptBuilder::PROMPT_VERSION . ')', $prefix[0]);
        self::assertSame("# Knowledge base\n\n## tone.md\n\nFriendly.\n\n# Instructions for Product\n\nWrite for shoppers.\n", $prefix[1]);
        self::assertStringStartsWith("# Fields to produce (language: de)\n", $prefix[2]);
        self::assertStringContainsString('- title: "Title"', $prefix[2]);
    }

    public function testEmptyKnowledgeBaseAndNoInstructionsAreSaidSo(): void
    {
        $block = $this->builder->knowledgeBlock(new ClassSettings('Product', ['title'], [], ''), new AssembledContext('', [], 0));

        self::assertSame("# Knowledge base\n\n(no knowledge base configured)\n", $block);
    }

    public function testUserMessageListsFilledFieldsAndTheRequest(): void
    {
        $specs = [
            new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', null, true, 80, [], null),
            new FieldSpec('short_description', FieldSpec::TYPE_TEXTAREA, 'Short', null, true, null, [], null),
        ];

        $message = $this->builder->userMessage('Product', 'de', ['name' => 'LENOVO LOQ 17IRX10', 'description' => 'i7, RTX 5060'], $specs);

        self::assertSame(
            "Object of class Product (language: de).\n\nFilled fields:\n- name: LENOVO LOQ 17IRX10\n- description: i7, RTX 5060\n\nProduce: title, short_description\n",
            $message
        );
        self::assertStringContainsString("No fields are filled yet.\n\nProduce: title", $this->builder->userMessage('Product', '', [], [$specs[0]]));
        self::assertStringStartsWith("Object of class Product.\n", $this->builder->userMessage('Product', '', [], [$specs[0]]));
    }

    public function testRequestCarriesTheSchemaOfTheSpecsAndAStablePrefixHash(): void
    {
        $specs = [new FieldSpec('title', FieldSpec::TYPE_INPUT, 'Title', null, true, 80, [], null)];

        $a = $this->builder->request(['i', 'k', 'f'], 'object a', $specs);
        $b = $this->builder->request(['i', 'k', 'f'], 'object b', $specs);

        self::assertSame(['title'], $a->getSchema()['required']);
        self::assertSame($a->getPrefixHash(), $b->getPrefixHash(), 'the object does not change the prefix');
        self::assertNotSame($a->getPrefixHash(), $this->builder->request(['i', 'k', 'f2'], 'object a', $specs)->getPrefixHash());
        self::assertSame("i\n\nk\n\nf", $a->getPrefixText());
    }
}
