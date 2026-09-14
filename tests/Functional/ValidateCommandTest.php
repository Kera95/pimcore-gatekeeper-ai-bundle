<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Tests\Functional;

use Pimcore\Model\Asset;
use Symfony\Component\Config\Definition\Processor;
use Tsf\GatekeeperAiBundle\DependencyInjection\Configuration;
use Tsf\GatekeeperAiBundle\Model\ClassSettings;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Config\SettingsValidator;
use Tsf\GatekeeperAiBundle\Service\Field\FieldPolicy;
use Tsf\GatekeeperAiBundle\Tests\Support\Fixture\ClassFixtures;
use Tsf\GatekeeperAiBundle\Tests\Support\FunctionalTestCase;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;
use Tsf\GatekeeperBundle\Service\FieldReader;
use Tsf\GatekeeperBundle\Service\LanguageProvider;

final class ValidateCommandTest extends FunctionalTestCase
{
    public function testReportsTheProblemsOfTheTestConfiguration(): void
    {
        $tester = $this->runCommand('tsf:gatekeeper:ai:validate');
        $output = $tester->getDisplay();

        self::assertSame(1, $tester->getStatusCode(), $output);
        self::assertStringContainsString('provider anthropic, model claude-opus-5, knowledge base /gatekeeper/context', $output);
        self::assertStringContainsString('Knowledge base folder "/gatekeeper/context" does not exist', $output);
        self::assertStringNotContainsString('No API key', $output);
        self::assertStringNotContainsString('test-key-never-sent', $output, 'the key is never printed');

        self::assertStringContainsString('ERROR GkProduct (enrich: title, description, sku, weight, seo_title; languages: en, de)', $output);
        self::assertStringContainsString('"sku" is on the deny list', $output);
        self::assertStringContainsString('"weight" is of type numeric', $output);
        self::assertStringContainsString('warning: enrich: "seo_title" is not required by any profile', $output);

        self::assertStringContainsString('OK    GkCategory (enrich: title)', $output);
        self::assertStringContainsString('1 check(s) failed', $output);
    }

    public function testAValidConfigurationPasses(): void
    {
        $validator = $this->validator([
            'anthropic' => ['api_key' => 'k'],
            'classes' => [ClassFixtures::PRODUCT => ['enrich' => ['title', 'description'], 'languages' => ['de']]],
        ]);

        $folder = Asset\Service::createFolderByPath('/gatekeeper/context');
        try {
            self::assertSame(['problems' => [], 'warnings' => []], $validator->validateGlobal());
        } finally {
            $folder->delete();
        }

        self::assertSame(
            ['problems' => [], 'warnings' => []],
            $validator->validateClass(new ClassSettings(ClassFixtures::PRODUCT, ['title', 'description'], ['de'], ''))
        );
    }

    public function testGlobalProblems(): void
    {
        $validator = $this->validator(['anthropic' => ['api_key' => '', 'model' => 'claude-unknown']]);
        $result = $validator->validateGlobal();

        self::assertCount(2, $result['problems']);
        self::assertStringContainsString('No API key', $result['problems'][0]);
        self::assertStringContainsString('No pricing for model "claude-unknown"', $result['problems'][1]);
    }

    public function testClassProblems(): void
    {
        $validator = $this->validator([]);

        self::assertSame(
            ['problems' => ['Class "GkNothing" has no rule under tsf_gatekeeper.classes; nothing is reported missing, so nothing can be proposed.'], 'warnings' => []],
            $validator->validateClass(new ClassSettings('GkNothing', ['title'], [], ''))
        );

        $result = $validator->validateClass(new ClassSettings(ClassFixtures::PRODUCT, ['nope', 'sku', 'weight', 'seo_title'], ['xx'], ''));
        self::assertCount(4, $result['problems']);
        self::assertSame([
            'enrich: field "nope" does not exist on the class (top-level or localized).',
            'enrich: "sku" is on the deny list (tsf_gatekeeper_ai.fields.deny).',
            'enrich: "weight" is of type numeric; only input, textarea, wysiwyg, select, multiselect can be proposed.',
        ], array_slice($result['problems'], 0, 3));
        self::assertStringStartsWith('languages: "xx" is not a valid system language (', $result['problems'][3]);
        self::assertSame(['enrich: "seo_title" is not required by any profile of the Gatekeeper rule, so it is never reported missing and never proposed.'], $result['warnings']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validator(array $config): SettingsValidator
    {
        $settings = new Settings((new Processor())->processConfiguration(new Configuration(), [$config]));
        /** @var RuleSet $rules */
        $rules = $this->service(RuleSet::class);
        /** @var FieldReader $fieldReader */
        $fieldReader = $this->service(FieldReader::class);
        /** @var LanguageProvider $languages */
        $languages = $this->service(LanguageProvider::class);

        return new SettingsValidator($settings, $rules, $fieldReader, new FieldPolicy($settings), $languages);
    }
}
