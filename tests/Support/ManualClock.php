<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use Tracewick\Clock\Clock;

/**
 * Clock that only moves when a test advances it.
 */
final class ManualClock implements Clock
{
    public function __construct(private int $nowNs = 1_000_000_000_000_000_000) {}

    public function nowNs(): int
    {
        return $this->nowNs;
    }

    public function advanceMs(int $milliseconds): void
    {
        $this->advanceNs($milliseconds * 1_000_000);
    }

    public function advanceNs(int $nanoseconds): void
    {
        $this->nowNs += $nanoseconds;
    }
}
