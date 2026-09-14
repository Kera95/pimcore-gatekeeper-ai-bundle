<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    // the throwaway project of the functional suite: generated classes, caches and the
    // service reference Symfony writes next to its configuration
    ->exclude('Support/App/var')
    ->notPath('Support/App/config/reference.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    // the bundle targets PHP 8.1 and CI runs the tool on 8.3; allow the newer runtimes of the
    // development containers, where the fixer may not know the very latest syntax yet
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
    ])
    ->setFinder($finder);
