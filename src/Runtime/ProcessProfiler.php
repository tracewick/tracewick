<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

/**
 * Process-scoped holder for the {@see UnitOfWorkProfiler} started from
 * {@see AutoPrepend} / `auto_prepend_file`.
 *
 * Lets application code attach labels (for example a framework route) to the same profiler that
 * already started before the framework booted. Prefer a framework integration when one exists;
 * do not bind a second profiler for the same request.
 *
 * {@see clear()} is for tests only. Production code must not clear the holder mid-request.
 */
final class ProcessProfiler
{
    private static ?UnitOfWorkProfiler $profiler = null;

    private function __construct() {}

    /**
     * Remembers the profiler for this process. The first bind wins; later calls are ignored.
     */
    public static function bind(UnitOfWorkProfiler $profiler): void
    {
        if (self::$profiler instanceof UnitOfWorkProfiler) {
            return;
        }

        self::$profiler = $profiler;
    }

    public static function get(): ?UnitOfWorkProfiler
    {
        return self::$profiler;
    }

    /**
     * Drops the bound profiler. For tests only.
     */
    public static function clear(): void
    {
        self::$profiler = null;
    }
}
