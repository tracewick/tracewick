<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

/**
 * Counter store backed by APCu shared memory.
 *
 * @internal prefer {@see CounterStoreFactory}
 */
final class ApcuCounterStore implements CounterStore
{
    private const PREFIX = 'tracewick:';

    public function increment(string $key, int $by = 1): void
    {
        if ($by < 1 || !\function_exists('apcu_add') || !\function_exists('apcu_inc')) {
            return;
        }

        $full = self::PREFIX . $key;
        if (!apcu_add($full, $by)) {
            apcu_inc($full, $by);
        }
    }

    public function get(string $key): int
    {
        if (!\function_exists('apcu_fetch')) {
            return 0;
        }

        $value = apcu_fetch(self::PREFIX . $key);

        return \is_int($value) ? max(0, $value) : 0;
    }

    public function all(): array
    {
        if (!class_exists(\APCUIterator::class, false)) {
            return [];
        }

        $iterator = new \APCUIterator('/^' . preg_quote(self::PREFIX, '/') . '/');
        $out = [];
        foreach ($iterator as $entry) {
            if (!\is_array($entry) || !isset($entry['key'], $entry['value']) || !\is_string($entry['key']) || !\is_int($entry['value'])) {
                continue;
            }

            $out[substr($entry['key'], \strlen(self::PREFIX))] = max(0, $entry['value']);
        }

        ksort($out, \SORT_STRING);

        return $out;
    }
}
