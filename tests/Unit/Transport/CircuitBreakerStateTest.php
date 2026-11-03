<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Transport\CircuitBreakerState;

#[CoversClass(CircuitBreakerState::class)]
final class CircuitBreakerStateTest extends TestCase
{
    public function testIsClosedOnlyWithoutFailuresAndOpenPeriod(): void
    {
        self::assertTrue((new CircuitBreakerState())->isClosed());
        self::assertFalse((new CircuitBreakerState(1))->isClosed());
        self::assertFalse((new CircuitBreakerState(0, 1))->isClosed());
    }
}
