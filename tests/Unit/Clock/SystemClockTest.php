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

        // The clock reads the monotonic clock just before its system time anchor, so the anchor is
        // late by however long that took, and microtime() truncates to whole microseconds. A
        // millisecond of tolerance absorbs both, including a preemption between the two reads,
        // and is still far below any sampling period.
        self::assertGreaterThanOrEqual($before - 1_000_000, $now);
        self::assertLessThanOrEqual($after + 1_000_000, $now);
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

    public function testReanchorsWhenWallDriftsBeyondThreshold(): void
    {
        $wall = 1_700_000_000_000_000_000;
        $mono = 100;
        $clock = new SystemClock(
            static function () use (&$wall): int {
                return $wall;
            },
            static function () use (&$mono): int {
                return $mono;
            },
        );

        self::assertSame(1_700_000_000_000_000_000, $clock->nowNs());
        self::assertSame(0, $clock->reanchorCount());

        // Monotonic advances 50 ns; wall stays put — within threshold.
        $mono = 150;
        self::assertSame(1_700_000_000_000_000_050, $clock->nowNs());
        self::assertSame(0, $clock->reanchorCount());

        // Wall jumps by more than 1 s while monotonic barely moves (suspend / NTP step).
        // Projected is still ~base+60; wall must exceed projected by more than the threshold.
        $wall = 1_700_000_000_000_000_000 + SystemClock::DRIFT_THRESHOLD_NS + 1_000;
        $mono = 160;
        self::assertSame($wall, $clock->nowNs());
        self::assertSame(1, $clock->reanchorCount());

        // After re-anchor, further monotonic advances track the new wall.
        $mono = 260;
        self::assertSame($wall + 100, $clock->nowNs());
        self::assertSame(1, $clock->reanchorCount());
    }

    public function testReanchorsWhenProjectedDriftsAheadOfWall(): void
    {
        $wall = 1_700_000_000_000_000_000;
        $mono = 0;
        $clock = new SystemClock(
            static function () use (&$wall): int {
                return $wall;
            },
            static function () use (&$mono): int {
                return $mono;
            },
        );

        // Monotonic ran during a pause of wall time (projected ahead of wall by > 1 s).
        $mono = SystemClock::DRIFT_THRESHOLD_NS + 10;
        self::assertSame($wall, $clock->nowNs());
        self::assertSame(1, $clock->reanchorCount());
    }
}
