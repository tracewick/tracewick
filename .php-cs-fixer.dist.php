<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->append([__FILE__, __DIR__ . '/rector.php', __DIR__ . '/composer-dependency-analyser.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        '@PHP82Migration' => true,
        'declare_strict_types' => true,
        'final_class' => true,
        'global_namespace_import' => ['import_classes' => false, 'import_constants' => false, 'import_functions' => false],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'php_unit_test_case_static_method_calls' => ['call_type' => 'self'],
        'strict_comparison' => true,
        'strict_param' => true,
    ])
    ->setFinder($finder);
