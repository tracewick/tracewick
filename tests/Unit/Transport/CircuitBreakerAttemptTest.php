<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Transport\CircuitBreakerAttempt;
use Tracewick\Transport\CircuitBreakerState;

#[CoversClass(CircuitBreakerAttempt::class)]
#[UsesClass(CircuitBreakerState::class)]
final class CircuitBreakerAttemptTest extends TestCase
{
    public function testExposesGenerationStateAndTrialFlag(): void
    {
        $before = new CircuitBreakerState(2, 9, 4);
        $attempt = new CircuitBreakerAttempt(5, $before, true);

        self::assertSame(5, $attempt->generation);
        self::assertSame($before, $attempt->stateBefore);
        self::assertTrue($attempt->takingTrial);
    }
}
