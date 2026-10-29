<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // ext-excimer is optional at install time: Tracewick must fail open when it is missing.
    ->ignoreErrorsOnExtension('ext-excimer', [ErrorType::SHADOW_DEPENDENCY])
    // ext-pcntl is optional: without it, tracewick-drain stops on signals without finishing the current file.
    ->ignoreErrorsOnExtension('ext-pcntl', [ErrorType::SHADOW_DEPENDENCY]);
