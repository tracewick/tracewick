<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Clock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testReturnsUnixTimeInNanoseconds(): void
    {
        $before = (int) (microtime(true) * 1_000_000) * 1_000;
        $now = (new SystemClock())->nowNs();
        $after = (int) (microtime(true) * 1_000_000) * 1_000;

        // One microsecond of tolerance for the rounding of the float system time.
        self::assertGreaterThanOrEqual($before - 1_000, $now);
        self::assertLessThanOrEqual($after + 1_000, $now);
    }

    public function testNeverGoesBackwards(): void
    {
        $clock = new SystemClock();
        $previous = $clock->nowNs();

        for ($i = 0; $i < 1_000; ++$i) {
            $current = $clock->nowNs();
            self::assertGreaterThanOrEqual($previous, $current);
            $previous = $current;
        }
    }
}
