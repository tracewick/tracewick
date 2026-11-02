<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Profile\Labels;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

/**
 * Boots a {@see UnitOfWorkProfiler} for PHP's `auto_prepend_file`, before the application loads.
 *
 * Uses a {@see SpoolSink} only (the same rule as PHP-FPM handlers). Fail-open: {@see boot()} never
 * throws into the host. Requires `TRACEWICK_APPLICATION` and `TRACEWICK_SPOOL_DIR`.
 *
 * @see ProcessProfiler
 */
final class AutoPrepend
{
    public const ENV_APPLICATION = 'TRACEWICK_APPLICATION';

    public const ENV_SPOOL_DIR = 'TRACEWICK_SPOOL_DIR';

    public const ENV_ENV = 'TRACEWICK_ENV';

    public const ENV_PROJECT_ROOT = 'TRACEWICK_PROJECT_ROOT';

    private static bool $missingConfigWarned = false;

    private function __construct() {}

    /**
     * Creates the profiler, starts one unit of work, registers shutdown delivery, and binds
     * {@see ProcessProfiler}. Safe to call more than once: a second boot is a no-op when already
     * bound.
     *
     * @param array<string, string> $environment environment map; defaults to {@see getenv()} values
     *                                           for the known keys when omitted
     * @param array<string, mixed>  $server      request server map; defaults to `$_SERVER`
     */
    public static function boot(?array $environment = null, ?array $server = null): void
    {
        try {
            if (ProcessProfiler::get() instanceof UnitOfWorkProfiler) {
                return;
            }

            $environment ??= self::defaultEnvironment();
            $server ??= self::requestServer();

            $application = self::nonEmpty($environment[self::ENV_APPLICATION] ?? null);
            $spoolDir = self::nonEmpty($environment[self::ENV_SPOOL_DIR] ?? null);
            if (null === $application || null === $spoolDir) {
                self::warnMissingConfig();

                return;
            }

            $staticLabels = [];
            $envLabel = self::nonEmpty($environment[self::ENV_ENV] ?? null);
            if (null !== $envLabel) {
                $staticLabels['env'] = $envLabel;
            }

            $projectRoot = self::nonEmpty($environment[self::ENV_PROJECT_ROOT] ?? null);

            $profiler = UnitOfWorkProfiler::create(
                new SpoolSink($application, new SpoolConfig($spoolDir)),
                new ProfilerConfig(new Labels($staticLabels)),
                new SamplerConfig(projectRoot: $projectRoot),
            );
            $profiler->start(self::requestLabels($server));
            $profiler->registerShutdown();
            ProcessProfiler::bind($profiler);
        } catch (\Throwable) {
            // Fail-open: auto_prepend must never break the host application.
        }
    }

    /**
     * Path-only route label from the request server map. Query strings are stripped so raw URLs
     * do not become Pyroscope series labels.
     *
     * @param array<string, mixed> $server
     */
    public static function routeFromServer(array $server): string
    {
        if (\PHP_SAPI === 'cli' && !isset($server['REQUEST_URI']) && !isset($server['REQUEST_METHOD'])) {
            return 'cli';
        }

        $uri = $server['REQUEST_URI'] ?? null;
        if (!\is_string($uri) || '' === $uri) {
            return 'unmatched';
        }

        $path = parse_url($uri, \PHP_URL_PATH);
        if (!\is_string($path) || '' === $path) {
            // REQUEST_URI without a scheme may still be a path; strip the query by hand.
            $cut = strpos($uri, '?');

            return false === $cut ? $uri : substr($uri, 0, $cut);
        }

        return $path;
    }

    /**
     * @internal resets the once-per-process missing-config warning; for tests only
     */
    public static function resetWarningsForTests(): void
    {
        self::$missingConfigWarned = false;
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, string>
     */
    private static function requestLabels(array $server): array
    {
        $labels = [];

        $method = $server['REQUEST_METHOD'] ?? null;
        if (\is_string($method) && '' !== $method) {
            $labels['method'] = $method;
        }

        $labels['route'] = self::routeFromServer($server);

        return $labels;
    }

    /**
     * @return array<string, mixed>
     */
    private static function requestServer(): array
    {
        $server = [];
        foreach ($_SERVER as $key => $value) {
            if (\is_string($key)) {
                $server[$key] = $value;
            }
        }

        return $server;
    }

    /**
     * @return array<string, string>
     */
    private static function defaultEnvironment(): array
    {
        $keys = [
            self::ENV_APPLICATION,
            self::ENV_SPOOL_DIR,
            self::ENV_ENV,
            self::ENV_PROJECT_ROOT,
        ];
        $environment = [];
        foreach ($keys as $key) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
            if (\is_string($value) && '' !== $value) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    private static function nonEmpty(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }

    private static function warnMissingConfig(): void
    {
        if (self::$missingConfigWarned) {
            return;
        }

        self::$missingConfigWarned = true;
        error_log('Tracewick auto_prepend: set TRACEWICK_APPLICATION and TRACEWICK_SPOOL_DIR to enable profiling.');
    }
}
