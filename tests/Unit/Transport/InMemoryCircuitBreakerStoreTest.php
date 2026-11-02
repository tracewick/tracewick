<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Transport\CircuitBreakerAttempt;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\InMemoryCircuitBreakerStore;

#[CoversClass(InMemoryCircuitBreakerStore::class)]
#[UsesClass(CircuitBreakerState::class)]
#[UsesClass(CircuitBreakerAttempt::class)]
final class InMemoryCircuitBreakerStoreTest extends TestCase
{
    public function testStartsClosedAndCompletesOutcomesByGeneration(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        self::assertTrue($store->load()->isClosed());

        $attempt = $store->acquire(0, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $attempt);
        self::assertTrue($store->complete($attempt->generation, new CircuitBreakerState(2, 5, $attempt->generation)));
        self::assertEquals(new CircuitBreakerState(2, 5, $attempt->generation), $store->load());
    }

    public function testIgnoresAStaleComplete(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $first = $store->acquire(0, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $first);
        $store->complete($first->generation, new CircuitBreakerState(3, 10, $first->generation));

        $second = $store->acquire(10, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $second);
        self::assertFalse($store->complete($first->generation, new CircuitBreakerState()));
        self::assertSame($second->generation, $store->load()->generation);
        self::assertTrue($store->complete($second->generation, new CircuitBreakerState(generation: $second->generation)));
        self::assertTrue($store->load()->isClosed());
    }

    public function testDeniesAcquireWhileOpenAndExtendsOnTrial(): void
    {
        $store = new InMemoryCircuitBreakerStore(new CircuitBreakerState(3, 1_000, 1));
        self::assertNull($store->acquire(500, 1_000, 5_000));

        $trial = $store->acquire(1_000, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $trial);
        self::assertTrue($trial->takingTrial);
        self::assertSame(1_000 + 1_000_000_000, $store->load()->openUntilNs);
    }

    public function testClampsACorruptOpenDeadlineOnAcquire(): void
    {
        $store = new InMemoryCircuitBreakerStore(new CircuitBreakerState(3, 86_400_000_000_000, 1));
        self::assertNull($store->acquire(0, 1_000, 5_000));
        self::assertSame(5_000_000_000, $store->load()->openUntilNs);
    }
}
