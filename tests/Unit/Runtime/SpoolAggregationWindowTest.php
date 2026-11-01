<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Runtime\SpoolAggregationWindow;

#[CoversClass(SpoolAggregationWindow::class)]
final class SpoolAggregationWindowTest extends TestCase
{
    public function testBucketsByStartTime(): void
    {
        $window = new SpoolAggregationWindow(10);

        self::assertSame(0, $window->idFor(0));
        self::assertSame(0, $window->idFor(9_999_999_999));
        self::assertSame(1, $window->idFor(10_000_000_000));
        self::assertTrue($window->isClosed(0, 10_000_000_000));
        self::assertFalse($window->isClosed(1, 10_000_000_000));
    }

    public function testRejectsOutOfRangeDurations(): void
    {
        $this->expectException(LogicException::class);

        new SpoolAggregationWindow(9);
    }
}
