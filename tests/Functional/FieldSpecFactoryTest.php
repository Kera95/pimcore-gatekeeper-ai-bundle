<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Functional;

use Pimcore\Model\DataObject\ClassDefinition;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Service\Field\FieldDescriber;
use Tsf\GatekeeperAiBundle\Service\Field\FieldSpecFactory;
use Tsf\GatekeeperAiBundle\Service\Field\SchemaBuilder;
use Tsf\GatekeeperAiBundle\Tests\Support\FunctionalTestCase;
use Tsf\GatekeeperAiBundle\Tests\Support\Fixture\ClassFixtures;

final class FieldSpecFactoryTest extends FunctionalTestCase
{
    public function testBuildsSpecsFromTheRealClassDefinition(): void
    {
        /** @var FieldSpecFactory $factory */
        $factory = $this->service(FieldSpecFactory::class);
        $class = ClassDefinition::getByName(ClassFixtures::PRODUCT);
        self::assertNotNull($class);

        $title = $this->spec($factory, $class, 'title');
        self::assertSame(FieldSpec::TYPE_INPUT, $title->getType());
        self::assertSame('Title', $title->getTitle());
        self::assertTrue($title->isLocalized());
        self::assertSame(190, $title->getMaxLength());

        $description = $this->spec($factory, $class, 'description');
        self::assertSame(FieldSpec::TYPE_TEXTAREA, $description->getType());
        self::assertTrue($description->isLocalized());
        self::assertNull($description->getMaxLength());

        $sku = $this->spec($factory, $class, 'sku');
        self::assertFalse($sku->isLocalized());

        self::assertSame('numeric', $factory->create($class, 'weight')?->getType(), 'the factory describes, the policy decides');
        self::assertNull($factory->create($class, 'nope'));

        /** @var SchemaBuilder $schemaBuilder */
        $schemaBuilder = $this->service(SchemaBuilder::class);
        $schema = $schemaBuilder->build([$title, $description]);
        self::assertSame(['title', 'description'], $schema['required']);

        /** @var FieldDescriber $describer */
        $describer = $this->service(FieldDescriber::class);
        self::assertStringContainsString('- title: "Title" — single-line text, no line breaks, at most 190 characters.', $describer->describe([$title, $description], 'de'));
    }

    private function spec(FieldSpecFactory $factory, ClassDefinition $class, string $path): FieldSpec
    {
        $spec = $factory->create($class, $path);
        self::assertNotNull($spec, $path);

        return $spec ?? throw new \LogicException($path);
    }
}
