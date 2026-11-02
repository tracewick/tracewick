<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

/**
 * Host-wide integer counters that survive a single PHP-FPM request when backed by APCu or a file.
 *
 * Every method is fail-open: implementations must not throw into the host application.
 */
interface CounterStore
{
    public function increment(string $key, int $by = 1): void;

    public function get(string $key): int;

    /**
     * @return array<string, int>
     */
    public function all(): array;
}
