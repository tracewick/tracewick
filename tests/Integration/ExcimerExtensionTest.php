<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Smoke test proving that the test environment runs a real ext-excimer.
 */
#[CoversNothing]
#[RequiresPhpExtension('excimer')]
final class ExcimerExtensionTest extends TestCase
{
    public function testProfilerCollectsSamples(): void
    {
        $profiler = new \ExcimerProfiler();
        $profiler->setEventType(EXCIMER_REAL);
        $profiler->setPeriod(0.001);
        $profiler->start();

        $deadline = hrtime(true) + 50_000_000;
        while (hrtime(true) < $deadline) {
            // Busy-wait so that the timer fires several times.
        }

        $profiler->stop();

        self::assertGreaterThan(0, $profiler->getLog()->getEventCount());
    }
}
