<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\Service\Config;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\ClassDefinition;
use Tsf\GatekeeperAiBundle\Model\ClassSettings;
use Tsf\GatekeeperAiBundle\Service\Field\FieldPolicy;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;
use Tsf\GatekeeperBundle\Service\FieldReader;
use Tsf\GatekeeperBundle\Service\LanguageProvider;

use function count;
use function in_array;
use function sprintf;

/**
 * Runtime checks the container cannot do: the API key is present, the model has a price, the
 * knowledge base folder exists, every enriched class has a Gatekeeper rule and its fields exist,
 * may be proposed and are actually required by that rule. Problems stop a run, warnings do not.
 */
class SettingsValidator
{
    public function __construct(
        private readonly Settings $settings,
        private readonly RuleSet $rules,
        private readonly FieldReader $fieldReader,
        private readonly FieldPolicy $policy,
        private readonly LanguageProvider $languages,
    ) {
    }

    /**
     * Provider, pricing and knowledge base folder
     *
     * @return array{problems: string[], warnings: string[]}
     */
    public function validateGlobal(): array
    {
        $problems = [];
        $warnings = [];

        if ($this->settings->getApiKey() === null) {
            $problems[] = 'No API key: set ANTHROPIC_API_KEY in the environment (or tsf_gatekeeper_ai.anthropic.api_key).';
        }

        $model = $this->settings->getModel();
        if ($this->settings->getPricing($model) === null) {
            $problems[] = sprintf('No pricing for model "%s": add tsf_gatekeeper_ai.pricing.%s (input, output, cache_write, cache_read in USD per million tokens) so the cost ceiling can work.', $model, $model);
        }

        $folder = $this->settings->getContextFolder();
        if (!$this->loadFolder($folder) instanceof Asset\Folder) {
            $warnings[] = sprintf('Knowledge base folder "%s" does not exist in the asset tree; the model gets no knowledge base until it does.', $folder);
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    /**
     * @return array{problems: string[], warnings: string[]}
     */
    public function validateClass(ClassSettings $settings): array
    {
        $className = $settings->getClassName();

        $rule = $this->rules->get($className);
        if ($rule === null) {
            return ['problems' => [sprintf('Class "%s" has no rule under tsf_gatekeeper.classes; nothing is reported missing, so nothing can be proposed.', $className)], 'warnings' => []];
        }

        $class = $this->loadClass($className);
        if ($class === null) {
            return ['problems' => [sprintf('Class "%s" does not exist.', $className)], 'warnings' => []];
        }

        $problems = [];
        $warnings = [];
        if (!$rule->isEnabled()) {
            $warnings[] = sprintf('The Gatekeeper rule of "%s" is disabled; nothing is reported missing while it stays so.', $className);
        }

        $required = $rule->getAllRequiredFields();
        foreach ($settings->getEnrich() as $field) {
            $problem = $this->fieldReader->describeProblem($class, $field);
            if ($problem !== null) {
                $problems[] = sprintf('enrich: %s', $problem);

                continue;
            }

            $definition = $this->fieldReader->getDefinition($class, $field);
            $problem = $definition === null ? null : $this->policy->describeProblem($field, $definition);
            if ($problem !== null) {
                $problems[] = sprintf('enrich: %s', $problem);

                continue;
            }

            if (!in_array($field, $required, true)) {
                $warnings[] = sprintf('enrich: "%s" is not required by any profile of the Gatekeeper rule, so it is never reported missing and never proposed.', $field);
            }
        }

        $validLanguages = $this->languages->getValidLanguages();
        foreach ($settings->getLanguages() as $language) {
            if (count($validLanguages) > 0 && !in_array($language, $validLanguages, true)) {
                $problems[] = sprintf('languages: "%s" is not a valid system language (%s).', $language, implode(', ', $validLanguages));
            }
        }

        return ['problems' => $problems, 'warnings' => $warnings];
    }

    protected function loadClass(string $name): ?ClassDefinition
    {
        try {
            return ClassDefinition::getByName($name);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function loadFolder(string $path): ?Asset
    {
        try {
            return Asset::getByPath($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
