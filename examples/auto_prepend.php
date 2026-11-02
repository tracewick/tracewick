<?php

declare(strict_types=1);

/**
 * Tracewick bootstrap for PHP's `auto_prepend_file`.
 *
 * Starts sampling before the application (and framework) loads, writes to a local spool, and
 * exposes the profiler via {@see Tracewick\Runtime\ProcessProfiler} so later code can set a real
 * route label. Prefer a framework integration when one exists — do not also start a second
 * profiler for the same request.
 *
 * php.ini / pool:
 *
 *     auto_prepend_file = /path/to/vendor/tracewick/tracewick/examples/auto_prepend.php
 *
 * Or one-off:
 *
 *     php -d auto_prepend_file=/path/to/examples/auto_prepend.php public/index.php
 *
 * Required environment:
 *
 * - TRACEWICK_APPLICATION — SpoolSink / Pyroscope service name
 * - TRACEWICK_SPOOL_DIR   — local spool directory (see docs/spool-format.md)
 *
 * Optional:
 *
 * - TRACEWICK_ENV           — static `env` label on every profile
 * - TRACEWICK_PROJECT_ROOT  — strip this prefix from frame paths
 * - TRACEWICK_AUTOLOAD      — absolute path to Composer `autoload.php` when the default
 *                             vendor layout does not apply
 *
 * Install ext-excimer separately (PECL is deprecated; PIE is recommended — see docs/install-excimer.md
 * and wave 6g). Runtime details: docs/runtime.md
 */

$autoload = getenv('TRACEWICK_AUTOLOAD');
if (!\is_string($autoload) || '' === $autoload) {
    // vendor/tracewick/tracewick/examples → vendor/autoload.php
    $autoload = dirname(__DIR__, 3) . '/autoload.php';
    if (!is_file($autoload)) {
        // Repository checkout: examples/ → vendor/autoload.php
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    }
}

if (!is_file($autoload)) {
    error_log('Tracewick auto_prepend: Composer autoload not found; set TRACEWICK_AUTOLOAD.');

    return;
}

require $autoload;

Tracewick\Runtime\AutoPrepend::boot();
