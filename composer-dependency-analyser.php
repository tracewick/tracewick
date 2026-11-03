<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // ext-excimer is optional at install time: Tracewick must fail open when it is missing.
    ->ignoreErrorsOnExtension('ext-excimer', [ErrorType::SHADOW_DEPENDENCY])
    // ext-pcntl is optional (signals in drain, multi-process store tests); listed under suggest.
    ->ignoreErrorsOnExtension('ext-pcntl', [ErrorType::SHADOW_DEPENDENCY])
    // ext-apcu is optional (CounterStore); listed under suggest.
    ->ignoreErrorsOnExtension('ext-apcu', [ErrorType::SHADOW_DEPENDENCY]);
