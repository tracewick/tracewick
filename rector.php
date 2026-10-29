<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withCache(__DIR__ . '/.rector.cache')
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withSkip([
        // PHP-CS-Fixer enforces self:: calls to PHPUnit assertions.
        PreferPHPUnitThisCallRector::class,
    ]);
