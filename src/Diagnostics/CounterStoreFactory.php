<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

/**
 * Picks APCu when available, otherwise a file under the given directory (or the system temp dir).
 */
final class CounterStoreFactory
{
    /**
     * @param ?callable(): bool $apcuAvailable override for tests; defaults to ext-apcu enabled
     */
    public static function create(?string $directory = null, ?callable $apcuAvailable = null): CounterStore
    {
        $apcuAvailable ??= static fn(): bool => \extension_loaded('apcu') && apcu_enabled();
        if ($apcuAvailable()) {
            return new ApcuCounterStore();
        }

        $directory ??= sys_get_temp_dir() . '/tracewick-counters';

        return FileCounterStore::inDirectory($directory);
    }
}
