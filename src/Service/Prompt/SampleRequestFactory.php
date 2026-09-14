<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Prompt;

use Pimcore\Model\DataObject\ClassDefinition;
use Tsf\GatekeeperAiBundle\Model\ClassSettings;
use Tsf\GatekeeperAiBundle\Model\EnrichmentRequest;
use Tsf\GatekeeperAiBundle\Model\FieldSpec;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Context\KnowledgeBase;
use Tsf\GatekeeperAiBundle\Service\Field\FieldPolicy;
use Tsf\GatekeeperAiBundle\Service\Field\FieldSpecFactory;
use Tsf\GatekeeperBundle\Service\LanguageProvider;

use function count;

/**
 * A request shaped like the first one a propose run would send - the real prefix of the first
 * configured class, a placeholder object - so the setup can be checked against the live API
 * (key, model, exact prefix size) without generating anything.
 */
final class SampleRequestFactory
{
    public function __construct(
        private readonly Settings $settings,
        private readonly KnowledgeBase $knowledgeBase,
        private readonly FieldSpecFactory $specs,
        private readonly FieldPolicy $policy,
        private readonly PromptBuilder $prompts,
        private readonly LanguageProvider $languages,
    ) {
    }

    public function create(): EnrichmentRequest
    {
        $class = $this->firstClass();
        $className = $class?->getClassName() ?? 'Object';
        $language = $this->language($class);
        $specs = $class === null ? [] : $this->enrichableSpecs($class);

        $prefix = $this->prompts->prefix(
            $class ?? new ClassSettings($className, [], [], ''),
            $this->knowledgeBase->assemble($class?->getClassName()),
            $specs,
            $language
        );
        $message = $this->prompts->userMessage($className, $language, ['name' => 'Sample object'], $specs);

        return $this->prompts->request($prefix, $message, $specs);
    }

    private function firstClass(): ?ClassSettings
    {
        foreach ($this->settings->getClasses() as $class) {
            return $class;
        }

        return null;
    }

    private function language(?ClassSettings $class): string
    {
        $languages = $class?->getLanguages() ?? [];
        if (count($languages) === 0) {
            $languages = $this->languages->getValidLanguages();
        }

        return $languages[0] ?? '';
    }

    /**
     * @return FieldSpec[]
     */
    private function enrichableSpecs(ClassSettings $class): array
    {
        try {
            $definition = ClassDefinition::getByName($class->getClassName());
        } catch (\Throwable) {
            $definition = null;
        }
        if ($definition === null) {
            return [];
        }

        $specs = [];
        foreach ($class->getEnrich() as $path) {
            $data = $this->specs->definition($definition, $path);
            if ($data === null || $this->policy->describeProblem($path, $data) !== null) {
                continue;
            }
            $spec = $this->specs->create($definition, $path);
            if ($spec !== null) {
                $specs[] = $spec;
            }
        }

        return $specs;
    }
}
