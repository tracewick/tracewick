<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

/**
 * In-process counters for tests and single-process hosts.
 */
final class ArrayCounterStore implements CounterStore
{
    /** @var array<string, int> */
    private array $counters = [];

    public function increment(string $key, int $by = 1): void
    {
        if ($by < 1 || '' === $key) {
            return;
        }

        $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;
    }

    public function get(string $key): int
    {
        return $this->counters[$key] ?? 0;
    }

    public function all(): array
    {
        $counters = $this->counters;
        ksort($counters, \SORT_STRING);

        return $counters;
    }
}
