<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\InMemoryCircuitBreakerStore;

#[CoversClass(InMemoryCircuitBreakerStore::class)]
#[UsesClass(CircuitBreakerState::class)]
final class InMemoryCircuitBreakerStoreTest extends TestCase
{
    public function testStartsClosedAndKeepsTheLastState(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        self::assertTrue($store->load()->isClosed());

        $state = new CircuitBreakerState(2, 5);
        $store->save($state);

        self::assertSame($state, $store->load());
    }

    public function testUpdateAppliesTheUpdaterToTheCurrentState(): void
    {
        $store = new InMemoryCircuitBreakerStore();
        $store->save(new CircuitBreakerState(1));

        $updated = $store->update(static fn(CircuitBreakerState $state): CircuitBreakerState => new CircuitBreakerState($state->consecutiveFailures + 1, 9));

        self::assertEquals(new CircuitBreakerState(2, 9), $updated);
        self::assertEquals(new CircuitBreakerState(2, 9), $store->load());
    }
}
