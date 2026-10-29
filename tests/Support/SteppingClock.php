<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use Tracewick\Clock\Clock;

/**
 * Deterministic clock that advances by a fixed step on every reading.
 */
final class SteppingClock implements Clock
{
    public function __construct(
        private int $nowNs = 1_000_000_000_000_000_000,
        private readonly int $stepNs = 1_000_000,
    ) {}

    public function nowNs(): int
    {
        $now = $this->nowNs;
        $this->nowNs += $this->stepNs;

        return $now;
    }
}
