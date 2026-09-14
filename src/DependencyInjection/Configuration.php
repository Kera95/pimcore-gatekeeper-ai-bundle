<?php

declare(strict_types=1);

namespace Tsf\GatekeeperAiBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function count;
use function is_string;

final class Configuration implements ConfigurationInterface
{
    public const PROVIDERS = ['anthropic'];

    public const DEFAULT_MODEL = 'claude-opus-5';

    public const EFFORTS = ['low', 'medium', 'high'];

    public const CACHE_TTLS = ['5m', '1h'];

    public const DEFAULT_ASSET_FOLDER = '/gatekeeper/context';

    public const DEFAULT_DENY_FIELDS = ['sku', 'ean', 'gtin', 'price', 'id'];

    /**
     * USD per million tokens; cache_write is the 5 minute rate (1.25x input), cache_read 0.1x input.
     * Overridable per model under "pricing" when the rates change.
     */
    public const DEFAULT_PRICING = [
        'claude-opus-5' => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-sonnet-5' => ['input' => 2.00, 'output' => 10.00, 'cache_write' => 2.50, 'cache_read' => 0.20],
        'claude-haiku-4-5' => ['input' => 1.00, 'output' => 5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
    ];

    public const LANGUAGE_PATTERN = '/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tsf_gatekeeper_ai');

        $treeBuilder->getRootNode()
            ->children()
                ->enumNode('provider')
                    ->values(self::PROVIDERS)
                    ->defaultValue('anthropic')
                    ->info('The LLM provider. Only "anthropic" ships with this version.')
                ->end()
                ->arrayNode('anthropic')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('api_key')
                            ->defaultValue('%env(default::ANTHROPIC_API_KEY)%')
                            ->info('Anthropic API key. Keep it in the environment, never in a committed file.')
                        ->end()
                        ->scalarNode('model')
                            ->defaultValue(self::DEFAULT_MODEL)
                            ->cannotBeEmpty()
                            ->info('Model id, e.g. claude-opus-5 or claude-sonnet-5. Needs an entry under "pricing".')
                        ->end()
                        ->integerNode('max_tokens')
                            ->defaultValue(2048)
                            ->min(1)
                            ->info('Output token ceiling per request.')
                        ->end()
                        ->enumNode('effort')
                            ->values(self::EFFORTS)
                            ->defaultValue('low')
                            ->info('Reasoning effort. Copywriting from a knowledge base needs "low".')
                        ->end()
                        ->booleanNode('prompt_caching')
                            ->defaultTrue()
                            ->info('Cache the static prompt prefix (instructions, knowledge base, field descriptions) across requests.')
                        ->end()
                        ->enumNode('cache_ttl')
                            ->values(self::CACHE_TTLS)
                            ->defaultValue('5m')
                            ->info('Cache lifetime. "1h" costs 2x input per write and pays off for runs with long pauses.')
                        ->end()
                        ->integerNode('timeout')
                            ->defaultValue(60)
                            ->min(1)
                            ->info('HTTP timeout per request in seconds.')
                        ->end()
                        ->integerNode('max_retries')
                            ->defaultValue(3)
                            ->min(0)
                            ->info('Attempts per object for rate limits, overloads and network errors before the object is skipped.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('pricing')
                    ->info('USD per million tokens keyed by model id. Merged over the built-in rates; add an entry for models that are not listed.')
                    ->useAttributeAsKey('model')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->floatNode('input')->isRequired()->min(0)->end()
                            ->floatNode('output')->isRequired()->min(0)->end()
                            ->floatNode('cache_write')->isRequired()->min(0)->end()
                            ->floatNode('cache_read')->isRequired()->min(0)->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('context')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('asset_folder')
                            ->defaultValue(self::DEFAULT_ASSET_FOLDER)
                            ->cannotBeEmpty()
                            ->info('Asset folder holding the knowledge base as Markdown files.')
                            ->validate()
                                ->ifTrue(static fn ($v): bool => !is_string($v) || !str_starts_with($v, '/'))
                                ->thenInvalid('The asset folder must be an absolute asset path such as "/gatekeeper/context".')
                            ->end()
                        ->end()
                        ->integerNode('max_tokens')
                            ->defaultValue(20000)
                            ->min(1)
                            ->info('Ceiling for the assembled knowledge base; a larger one aborts the run.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_objects_per_run')
                            ->defaultValue(200)
                            ->min(1)
                            ->info('Hard stop for one propose run.')
                        ->end()
                        ->floatNode('max_cost_per_run')
                            ->defaultValue(10.0)
                            ->min(0)
                            ->info('Hard stop in USD for one propose run, measured from the reported token usage.')
                        ->end()
                        ->integerNode('avg_output_tokens_per_field')
                            ->defaultValue(150)
                            ->min(1)
                            ->info('Assumed output size per field, used by the cost estimate only.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('fields')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('deny')
                            ->defaultValue(self::DEFAULT_DENY_FIELDS)
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->info('Field names that are never proposed, whatever the class configuration says. Compared case-insensitively.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('classes')
                    ->info('Enrichment settings keyed by DataObject class name (e.g. Product). The class needs a rule under tsf_gatekeeper.classes.')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->append($this->enrichNode())
                            ->append($this->languagesNode())
                            ->scalarNode('instructions')
                                ->defaultValue('')
                                ->info('Optional text for the model, appended after the knowledge base for this class.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    private function enrichNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('enrich');
        $node
            ->info('Fields the model may fill, in the Gatekeeper addressing syntax. Only fields the gate reports missing are proposed.')
            ->isRequired()
            ->requiresAtLeastOneElement()
            ->scalarPrototype()
                ->cannotBeEmpty()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $fields): bool => count($fields) !== count(array_unique($fields)))
                ->thenInvalid('"enrich" contains duplicate field names.')
            ->end();

        return $node;
    }

    private function languagesNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('languages');
        $node
            ->info('Languages to propose for localized fields. Empty: the languages of the Gatekeeper rule.')
            ->scalarPrototype()
                ->validate()
                    ->ifTrue(static fn ($v): bool => !is_string($v) || preg_match(self::LANGUAGE_PATTERN, $v) !== 1)
                    ->thenInvalid('Invalid language code %s; expected something like "en" or "de_AT".')
                ->end()
            ->end();

        return $node;
    }
}
