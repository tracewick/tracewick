<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingCircuitBreakerStore;
use Tracewick\Tests\Support\ScriptedTransport;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\CircuitBreakerTransport;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;
use Tracewick\Transport\InMemoryCircuitBreakerStore;
use Tracewick\Transport\Payload;
use Tracewick\Transport\Transport;

#[CoversClass(CircuitBreakerTransport::class)]
#[UsesClass(CircuitBreakerConfig::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Payload::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(CircuitBreakerState::class)]
#[UsesClass(InMemoryCircuitBreakerStore::class)]
final class CircuitBreakerTransportTest extends TestCase
{
    private ManualClock $clock;

    protected function setUp(): void
    {
        $this->clock = new ManualClock();
    }

    public function testPassesDeliveriesThrough(): void
    {
        $inner = new ScriptedTransport(Delivery::delivered(), Delivery::rejected('bad'));
        $breaker = $this->breaker($inner);
        $payload = new Payload('body', 'text/plain');

        self::assertTrue($breaker->send($payload)->isDelivered());
        self::assertSame(DeliveryStatus::Rejected, $breaker->send($payload)->status);
        self::assertSame([$payload, $payload], $inner->sent);
        self::assertFalse($breaker->isOpen());
    }

    public function testOpensAfterTheThresholdOfConsecutiveFailures(): void
    {
        $inner = new ScriptedTransport(Delivery::failed('1'), Delivery::failed('2'), Delivery::failed('3'));
        $breaker = $this->breaker($inner);

        self::assertSame('1', $this->send($breaker)->reason);
        self::assertSame('2', $this->send($breaker)->reason);
        self::assertFalse($breaker->isOpen());
        self::assertSame('3', $this->send($breaker)->reason);
        self::assertTrue($breaker->isOpen());

        $skipped = $this->send($breaker);
        self::assertSame(DeliveryStatus::Skipped, $skipped->status);
        self::assertStringContainsString('Circuit breaker is open', $skipped->reason);
        self::assertCount(3, $inner->sent);
    }

    public function testSuccessResetsTheFailureCount(): void
    {
        $inner = new ScriptedTransport(
            Delivery::failed('1'),
            Delivery::failed('2'),
            Delivery::delivered(),
            Delivery::failed('3'),
            Delivery::failed('4'),
            Delivery::rejected('reachable'),
            Delivery::failed('5'),
            Delivery::failed('6'),
        );
        $breaker = $this->breaker($inner);

        for ($i = 0; $i < 8; ++$i) {
            $this->send($breaker);
        }

        self::assertFalse($breaker->isOpen());
        self::assertCount(8, $inner->sent);
    }

    public function testCountsFromZeroAfterASuccess(): void
    {
        $inner = new ScriptedTransport(Delivery::failed('1'), Delivery::delivered(), Delivery::failed('2'), Delivery::failed('3'));
        $breaker = $this->breaker($inner, new CircuitBreakerConfig(2, 1_000));

        for ($i = 0; $i < 3; ++$i) {
            $this->send($breaker);
        }

        self::assertFalse($breaker->isOpen());
        $this->send($breaker);
        self::assertTrue($breaker->isOpen());
    }

    public function testMeasuresLongOpenDurationsExactly(): void
    {
        $inner = new ScriptedTransport(Delivery::failed('1'), Delivery::delivered());
        $breaker = $this->breaker($inner, new CircuitBreakerConfig(1, 3_600_000));

        $this->send($breaker);
        $this->clock->advanceMs(3_599_999);
        self::assertSame(DeliveryStatus::Skipped, $this->send($breaker)->status);
        $this->clock->advanceMs(1);
        self::assertTrue($this->send($breaker)->isDelivered());
    }

    public function testSkippedDeliveriesDoNotCount(): void
    {
        $inner = new ScriptedTransport(
            Delivery::failed('1'),
            Delivery::failed('2'),
            Delivery::skipped('inner'),
            Delivery::failed('3'),
        );
        $breaker = $this->breaker($inner);

        $this->send($breaker);
        $this->send($breaker);
        self::assertSame('inner', $this->send($breaker)->reason);
        self::assertFalse($breaker->isOpen());
        $this->send($breaker);
        self::assertTrue($breaker->isOpen());
    }

    public function testStaysOpenForTheConfiguredDuration(): void
    {
        $inner = new ScriptedTransport(Delivery::failed('1'), Delivery::delivered());
        $breaker = $this->breaker($inner, new CircuitBreakerConfig(1, 1_000));

        $this->send($breaker);
        $this->clock->advanceMs(999);
        self::assertTrue($breaker->isOpen());
        self::assertSame(DeliveryStatus::Skipped, $this->send($breaker)->status);

        $this->clock->advanceMs(1);
        self::assertFalse($breaker->isOpen());
        self::assertTrue($this->send($breaker)->isDelivered());
        self::assertFalse($breaker->isOpen());
        self::assertCount(2, $inner->sent);
    }

    public function testAFailedTrialReopensAtOnce(): void
    {
        $inner = new ScriptedTransport(Delivery::failed('1'), Delivery::failed('2'), Delivery::failed('trial'), Delivery::delivered());
        $breaker = $this->breaker($inner, new CircuitBreakerConfig(2, 1_000));

        $this->send($breaker);
        $this->send($breaker);
        $this->clock->advanceMs(1_000);

        self::assertSame('trial', $this->send($breaker)->reason);
        self::assertTrue($breaker->isOpen());
        $this->clock->advanceMs(999);
        self::assertSame(DeliveryStatus::Skipped, $this->send($breaker)->status);
        $this->clock->advanceMs(1);
        self::assertTrue($this->send($breaker)->isDelivered());
    }

    public function testDefaultsToThreeFailuresAndTheSystemClock(): void
    {
        $inner = new ScriptedTransport(Delivery::failed('1'), Delivery::failed('2'), Delivery::failed('3'));
        $breaker = new CircuitBreakerTransport($inner);

        $this->send($breaker);
        $this->send($breaker);
        self::assertFalse($breaker->isOpen());
        $this->send($breaker);
        self::assertTrue($breaker->isOpen());
        self::assertSame(DeliveryStatus::Skipped, $this->send($breaker)->status);
    }

    public function testProcessesSharingAStoreCountFailuresTogether(): void
    {
        $store = new RecordingCircuitBreakerStore();
        $first = $this->breaker(new ScriptedTransport(Delivery::failed('1'), Delivery::failed('3')), store: $store);
        $second = $this->breaker(new ScriptedTransport(Delivery::failed('2')), store: $store);

        $this->send($first);
        $this->send($second);
        self::assertFalse($second->isOpen());
        $this->send($first);

        self::assertTrue($second->isOpen());
        self::assertSame(DeliveryStatus::Skipped, $this->send($second)->status);
    }

    public function testStopsCountingAtTheThreshold(): void
    {
        $store = new RecordingCircuitBreakerStore();
        $inner = new ScriptedTransport(Delivery::failed('1'), Delivery::failed('2'), Delivery::failed('trial'));
        $breaker = $this->breaker($inner, new CircuitBreakerConfig(2, 1_000), $store);

        $this->send($breaker);
        $this->send($breaker);
        $this->clock->advanceMs(1_000);
        $this->send($breaker);

        self::assertEquals(new CircuitBreakerState(2, $this->clock->nowNs() + 1_000_000_000), $store->load());
    }

    public function testWritesNothingWhileTheCircuitStaysClosed(): void
    {
        $store = new RecordingCircuitBreakerStore();
        $inner = new ScriptedTransport(Delivery::delivered(), Delivery::rejected('bad'), Delivery::skipped('inner'));
        $breaker = $this->breaker($inner, store: $store);

        for ($i = 0; $i < 3; ++$i) {
            $this->send($breaker);
        }

        self::assertSame([], $store->saved);
    }

    public function testClosesTheCircuitAfterASuccessfulTrial(): void
    {
        $store = new RecordingCircuitBreakerStore(new CircuitBreakerState(3, $this->clock->nowNs()));
        $breaker = $this->breaker(new ScriptedTransport(Delivery::delivered()), store: $store);

        self::assertTrue($this->send($breaker)->isDelivered());

        self::assertEquals(new CircuitBreakerState(), $store->load());
    }

    public function testAFailedTrialReopensEvenBelowTheThreshold(): void
    {
        $store = new RecordingCircuitBreakerStore(new CircuitBreakerState(1, $this->clock->nowNs()));
        $breaker = $this->breaker(new ScriptedTransport(Delivery::failed('trial')), store: $store);

        $this->send($breaker);

        self::assertEquals(new CircuitBreakerState(2, $this->clock->nowNs() + 1_000_000_000), $store->load());
    }

    public function testResetsTheFailureCountAfterASuccess(): void
    {
        $store = new RecordingCircuitBreakerStore(new CircuitBreakerState(2));
        $breaker = $this->breaker(new ScriptedTransport(Delivery::delivered()), store: $store);

        $this->send($breaker);

        self::assertEquals([new CircuitBreakerState()], $store->saved);
    }

    public function testKeepsOtherProcessesSkippingDuringTheTrial(): void
    {
        $store = new RecordingCircuitBreakerStore(new CircuitBreakerState(3, $this->clock->nowNs()));
        $other = $this->breaker(new ScriptedTransport(), store: $store);
        $statusSeenByOther = null;
        $inner = new class (function () use ($other, &$statusSeenByOther): void {
            $statusSeenByOther = $this->send($other)->status;
        }) implements Transport {
            /** @param \Closure(): void $whileSending */
            public function __construct(private readonly \Closure $whileSending) {}

            public function send(Payload $payload): Delivery
            {
                ($this->whileSending)();

                return Delivery::delivered();
            }
        };

        self::assertTrue($this->send($this->breaker($inner, store: $store))->isDelivered());

        self::assertSame(DeliveryStatus::Skipped, $statusSeenByOther);
        self::assertEquals(
            [new CircuitBreakerState(3, $this->clock->nowNs() + 1_000_000_000), new CircuitBreakerState()],
            $store->saved,
        );
    }

    private function breaker(Transport $inner, CircuitBreakerConfig $config = new CircuitBreakerConfig(3, 1_000), ?RecordingCircuitBreakerStore $store = null): CircuitBreakerTransport
    {
        return new CircuitBreakerTransport($inner, $config, $this->clock, $store ?? new InMemoryCircuitBreakerStore());
    }

    private function send(CircuitBreakerTransport $breaker): Delivery
    {
        return $breaker->send(new Payload('body', 'text/plain'));
    }
}
