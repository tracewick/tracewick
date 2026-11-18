<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Clock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;

#[CoversClass(SystemClock::class)]
final class SystemClockTest extends TestCase
{
    public function testLogsOnlyWhenReanchoring(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'tracewick-clock-log-');
        self::assertNotFalse($logFile);
        $previous = ini_set('error_log', $logFile);

        try {
            self::assertNotFalse($previous);
            $wall = 1_700_000_000_000_000_000;
            $clock = new SystemClock(
                static function () use (&$wall): int {
                    return $wall;
                },
                static fn(): int => 100,
            );

            $wall += 1_000_000_000;
            $clock->nowNs();
            self::assertSame('', file_get_contents($logFile));

            ++$wall;
            $clock->nowNs();
            $logged = file_get_contents($logFile);
            self::assertNotFalse($logged);
            self::assertSame(
                1,
                substr_count($logged, 'Tracewick SystemClock: re-anchored after wall/monotonic drift exceeded 1s.'),
            );

            $clock->nowNs();
            self::assertSame($logged, file_get_contents($logFile));
        } finally {
            if (false !== $previous) {
                ini_set('error_log', $previous);
            }
            unlink($logFile);
        }
    }
}
