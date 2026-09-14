<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Support\Fixture;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Layout;

/**
 * The DataObject classes the functional suite works with, created programmatically in the test
 * database. Their names carry a "Gk" prefix so they never collide with classes of a surrounding
 * project when the suite runs from inside one.
 *
 *  - GkCategory: name, localized title
 *  - GkProduct: sku, name, weight, localized title + description + seo_title, completeness (score field)
 */
final class ClassFixtures
{
    public const CATEGORY = 'GkCategory';

    public const PRODUCT = 'GkProduct';

    public static function create(): void
    {
        if (ClassDefinition::getByName(self::CATEGORY) === null) {
            self::createClass(self::CATEGORY, [
                self::input('name'),
                self::localized([self::input('title')]),
            ]);
        }

        if (ClassDefinition::getByName(self::PRODUCT) === null) {
            $score = new Data\Numeric();
            $score->setName('completeness');
            $score->setTitle('Completeness %');
            $score->setInteger(true);

            $weight = new Data\Numeric();
            $weight->setName('weight');
            $weight->setTitle('Weight');

            self::createClass(self::PRODUCT, [
                self::input('sku'),
                self::input('name'),
                $weight,
                self::localized([self::input('title'), self::textarea('description'), self::input('seo_title')]),
                $score,
            ]);
        }
    }

    /**
     * @param Data[] $fields
     */
    private static function createClass(string $name, array $fields): ClassDefinition
    {
        $class = new ClassDefinition();
        $class->setName($name);
        $class->setId($name);
        $class->setUserOwner(1);
        $class->setUserModification(1);
        $class->setLayoutDefinitions(self::panel($fields));
        $class->save();

        return $class;
    }

    /**
     * @param Data[]|Layout[] $children
     */
    private static function panel(array $children): Layout\Panel
    {
        $panel = new Layout\Panel();
        $panel->setName('pimcore_root');
        $panel->setChildren($children);

        return $panel;
    }

    /**
     * @param Data[] $children
     */
    private static function localized(array $children): Data\Localizedfields
    {
        $localized = new Data\Localizedfields();
        $localized->setName('localizedfields');
        $localized->setTitle('Localized');
        $localized->setChildren($children);

        return $localized;
    }

    private static function input(string $name): Data\Input
    {
        $field = new Data\Input();
        $field->setName($name);
        $field->setTitle(ucfirst($name));

        return $field;
    }

    private static function textarea(string $name): Data\Textarea
    {
        $field = new Data\Textarea();
        $field->setName($name);
        $field->setTitle(ucfirst($name));

        return $field;
    }
}
