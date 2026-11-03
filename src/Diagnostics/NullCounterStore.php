<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

/**
 * No-op counters for tests and hosts that do not want host-wide metrics.
 */
final class NullCounterStore implements CounterStore
{
    public function increment(string $key, int $by = 1): void {}

    public function get(string $key): int
    {
        return 0;
    }

    public function all(): array
    {
        return [];
    }
}
