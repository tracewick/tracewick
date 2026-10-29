<?php

declare(strict_types=1);

namespace Tracewick\Clock;

/**
 * Wall clock anchored once to the system time and advanced by the monotonic clock.
 *
 * Timestamps therefore never go backwards within a process, even if the system time is adjusted,
 * and keep nanosecond resolution that a float Unix time cannot represent.
 */
final readonly class SystemClock implements Clock
{
    private int $anchorUnixNs;

    private int $anchorMonotonicNs;

    public function __construct()
    {
        $this->anchorMonotonicNs = $this->monotonicNs();
        $this->anchorUnixNs = (int) (microtime(true) * 1_000_000) * 1_000;
    }

    public function nowNs(): int
    {
        return $this->anchorUnixNs + ($this->monotonicNs() - $this->anchorMonotonicNs);
    }

    private function monotonicNs(): int
    {
        // hrtime() returns a float only on 32-bit platforms, where integers cannot hold it.
        return (int) hrtime(true);
    }
}
