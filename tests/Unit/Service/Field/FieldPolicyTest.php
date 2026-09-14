<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Unit\Service\Field;

use Codeception\Test\Unit;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Symfony\Component\Config\Definition\Processor;
use Tsf\GatekeeperAiBundle\DependencyInjection\Configuration;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Field\FieldPolicy;

final class FieldPolicyTest extends Unit
{
    public function testTextLikeFieldsMayBeProposed(): void
    {
        $policy = $this->policy();

        self::assertNull($policy->describeProblem('title', new Data\Input()));
        self::assertNull($policy->describeProblem('description', new Data\Textarea()));
        self::assertNull($policy->describeProblem('body', new Data\Wysiwyg()));
        self::assertNull($policy->describeProblem('color', $this->select(new Data\Select())));
        self::assertNull($policy->describeProblem('tags', $this->select(new Data\Multiselect())));
        self::assertTrue($policy->isEnrichable('bricks.Dimensions.note', new Data\Input()));
    }

    public function testOtherTypesAreRefused(): void
    {
        $problem = $this->policy()->describeProblem('weight', new Data\Numeric());

        self::assertSame('"weight" is of type numeric; only input, textarea, wysiwyg, select, multiselect can be proposed.', $problem);
        self::assertFalse($this->policy()->isEnrichable('image', new Data\Image()));
    }

    public function testSelectsWithoutStaticOptionsAreRefused(): void
    {
        $policy = $this->policy();

        self::assertSame('"color" has no options in the class definition (an options provider is not supported); nothing to choose from.', $policy->describeProblem('color', new Data\Select()));
        self::assertStringContainsString('has no options', (string) $policy->describeProblem('tags', new Data\Multiselect()));
    }

    public function testDeniedNamesAreRefusedCaseInsensitivelyOnTheLastPathSegment(): void
    {
        $policy = $this->policy(['fields' => ['deny' => ['SKU', 'ean']]]);

        self::assertSame('"sku" is on the deny list (tsf_gatekeeper_ai.fields.deny).', $policy->describeProblem('sku', new Data\Input()));
        self::assertSame('"bricks.Ids.EAN" is on the deny list (tsf_gatekeeper_ai.fields.deny).', $policy->describeProblem('bricks.Ids.EAN', new Data\Input()));
        self::assertNull($policy->describeProblem('price', new Data\Input()), 'the configured list replaces the default one');
    }

    private function select(Data\Select|Data\Multiselect $definition): Data\Select|Data\Multiselect
    {
        $definition->setOptions([['key' => 'Red', 'value' => 'red']]);

        return $definition;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function policy(array $config = []): FieldPolicy
    {
        return new FieldPolicy(new Settings((new Processor())->processConfiguration(new Configuration(), [$config])));
    }
}
