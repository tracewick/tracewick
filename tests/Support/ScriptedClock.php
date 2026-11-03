<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use Tracewick\Clock\Clock;

/**
 * Clock that returns the given readings in order and then repeats the last one.
 */
final class ScriptedClock implements Clock
{
    /** @var non-empty-list<int> */
    private array $readings;

    public function __construct(int $first, int ...$rest)
    {
        $this->readings = [$first, ...array_values($rest)];
    }

    public function nowNs(): int
    {
        return \count($this->readings) > 1 ? array_shift($this->readings) : $this->readings[0];
    }
}
