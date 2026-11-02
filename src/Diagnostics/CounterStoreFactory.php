<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

/**
 * Picks APCu when available, otherwise a file under the given directory (or the system temp dir).
 */
final class CounterStoreFactory
{
    public static function create(?string $directory = null): CounterStore
    {
        if (\extension_loaded('apcu') && apcu_enabled()) {
            return new ApcuCounterStore();
        }

        $directory ??= sys_get_temp_dir() . '/tracewick-counters';

        return FileCounterStore::inDirectory($directory);
    }
}
