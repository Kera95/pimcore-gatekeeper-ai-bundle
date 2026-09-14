<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Prompt;

use Tsf\GatekeeperAiBundle\Model\ClassSettings;
use Tsf\GatekeeperAiBundle\Model\EnrichmentRequest;
use Tsf\GatekeeperAiBundle\Service\Config\Settings;
use Tsf\GatekeeperAiBundle\Service\Context\KnowledgeBase;
use Tsf\GatekeeperAiBundle\Service\Propose\RunPlanner;
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
        private readonly RunPlanner $planner,
        private readonly PromptBuilder $prompts,
        private readonly LanguageProvider $languages,
    ) {
    }

    public function create(): EnrichmentRequest
    {
        $class = $this->firstClass();
        $className = $class?->getClassName() ?? 'Object';
        $language = $this->language($class);
        $specs = $class === null ? [] : array_values($this->planner->enrichableSpecs($class));

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
}
