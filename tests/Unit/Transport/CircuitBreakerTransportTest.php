<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingCircuitBreakerStore;
use Tracewick\Tests\Support\RecordingLogger;
use Tracewick\Tests\Support\ScriptedTransport;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\CircuitBreakerAttempt;
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
#[UsesClass(CircuitBreakerAttempt::class)]
#[UsesClass(InMemoryCircuitBreakerStore::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(AtomicFile::class)]
final class CircuitBreakerTransportTest extends TestCase
{
    private ManualClock $clock;

    protected function setUp(): void
    {
        DiagnosticLogger::resetRateLimits();
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
            Delivery::delivered(),
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

    public function testRejectedPayloadsDoNotChangeTheBreakerState(): void
    {
        $store = new RecordingCircuitBreakerStore(new CircuitBreakerState(2));
        $breaker = $this->breaker(new ScriptedTransport(Delivery::rejected('bad payload')), store: $store);

        self::assertSame(DeliveryStatus::Rejected, $this->send($breaker)->status);
        self::assertSame([], $store->saved);
        self::assertSame(2, $store->load()->consecutiveFailures);
        self::assertNull($store->load()->openUntilNs);
        self::assertFalse($breaker->isOpen());
    }

    public function testAConfigurationErrorOpensImmediatelyForTheLongerPause(): void
    {
        $logger = new RecordingLogger();
        $directory = new TemporaryDirectory();
        $breaker = new CircuitBreakerTransport(
            new ScriptedTransport(Delivery::configuration('HTTP 401'), Delivery::delivered()),
            new CircuitBreakerConfig(3, 1_000, configurationOpenDurationMs: 5_000),
            $this->clock,
            new InMemoryCircuitBreakerStore(),
            new DiagnosticLogger($logger, $directory->path, clock: $this->clock),
        );

        try {
            self::assertSame(DeliveryStatus::Configuration, $this->send($breaker)->status);
            self::assertTrue($breaker->isOpen());
            $this->clock->advanceMs(4_999);
            self::assertSame(DeliveryStatus::Skipped, $this->send($breaker)->status);
            $this->clock->advanceMs(1);
            self::assertTrue($this->send($breaker)->isDelivered());
        } finally {
            $directory->remove();
        }

        self::assertSame(
            [
                'warning: Tracewick stopped sending profiles for {pauseMs} ms after a configuration error: {reason}',
                'info: Tracewick resumed sending profiles after a successful trial delivery.',
            ],
            $logger->lines(),
        );
        self::assertArrayHasKey(0, $logger->records);
        self::assertSame(['pauseMs' => 5_000, 'reason' => 'HTTP 401'], $logger->records[0]['context']);
    }

    public function testClampsACorruptOpenDeadlineToTheLongestConfiguredPause(): void
    {
        $farFuture = $this->clock->nowNs() + 86_400_000 * 1_000_000;
        $store = new RecordingCircuitBreakerStore(new CircuitBreakerState(3, $farFuture));
        $breaker = $this->breaker(
            new ScriptedTransport(Delivery::delivered()),
            new CircuitBreakerConfig(3, 1_000, configurationOpenDurationMs: 5_000),
            $store,
        );

        self::assertTrue($breaker->isOpen());
        self::assertSame(3, $store->load()->consecutiveFailures);
        self::assertSame($this->clock->nowNs() + 5_000_000_000, $store->load()->openUntilNs);
        self::assertSame(DeliveryStatus::Skipped, $this->send($breaker)->status);

        $this->clock->advanceMs(5_000);
        self::assertFalse($breaker->isOpen());
        self::assertTrue($this->send($breaker)->isDelivered());
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

        self::assertSame(2, $store->load()->consecutiveFailures);
        self::assertSame($this->clock->nowNs() + 1_000_000_000, $store->load()->openUntilNs);
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

        self::assertTrue($store->load()->isClosed());
    }

    public function testAFailedTrialReopensEvenBelowTheThreshold(): void
    {
        $store = new RecordingCircuitBreakerStore(new CircuitBreakerState(1, $this->clock->nowNs()));
        $breaker = $this->breaker(new ScriptedTransport(Delivery::failed('trial')), store: $store);

        $this->send($breaker);

        self::assertSame(2, $store->load()->consecutiveFailures);
        self::assertSame($this->clock->nowNs() + 1_000_000_000, $store->load()->openUntilNs);
    }

    public function testResetsTheFailureCountAfterASuccess(): void
    {
        $store = new RecordingCircuitBreakerStore(new CircuitBreakerState(2));
        $breaker = $this->breaker(new ScriptedTransport(Delivery::delivered()), store: $store);

        $this->send($breaker);

        self::assertCount(1, $store->saved);
        self::assertTrue($store->saved[0]->isClosed());
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
        self::assertCount(2, $store->saved);
        self::assertSame(3, $store->saved[0]->consecutiveFailures);
        self::assertSame($this->clock->nowNs() + 1_000_000_000, $store->saved[0]->openUntilNs);
        self::assertTrue($store->saved[1]->isClosed());
    }

    public function testStaleSuccessDoesNotCloseANewerOpenCircuit(): void
    {
        $store = new RecordingCircuitBreakerStore();
        $slow = $this->breaker(new ScriptedTransport(Delivery::delivered()), store: $store);
        $fast = $this->breaker(new ScriptedTransport(Delivery::failed('1'), Delivery::failed('2'), Delivery::failed('3')), new CircuitBreakerConfig(3, 1_000), $store);

        // Open the shared circuit from the fast sender.
        $this->send($fast);
        $this->send($fast);
        $this->send($fast);
        self::assertTrue($fast->isOpen());
        $openGeneration = $store->load()->generation;

        // A late complete from an older generation must not wipe the open state.
        self::assertFalse($store->complete($openGeneration - 1, new CircuitBreakerState()));
        self::assertTrue($slow->isOpen());
        self::assertSame($openGeneration, $store->load()->generation);
        self::assertNotNull($store->load()->openUntilNs);
    }

    public function testLogsWhenTheCircuitOpensAndCloses(): void
    {
        $directory = new TemporaryDirectory();
        $logger = new RecordingLogger();
        $inner = new ScriptedTransport(Delivery::delivered(), Delivery::failed('1'), Delivery::failed('2'), Delivery::failed('trial'), Delivery::delivered());
        $breaker = new CircuitBreakerTransport($inner, new CircuitBreakerConfig(2, 1_500), $this->clock, new InMemoryCircuitBreakerStore(), new DiagnosticLogger($logger, $directory->path, clock: $this->clock));

        try {
            $this->send($breaker);
            $this->send($breaker);
            self::assertSame([], $logger->lines());
            $this->send($breaker);
            $this->clock->advanceMs(1_500);
            $this->send($breaker);
            $this->clock->advanceMs(1_500);
            $this->send($breaker);
        } finally {
            $directory->remove();
        }

        self::assertSame(
            [
                'warning: Tracewick stopped sending profiles for {pauseMs} ms after {failures} failed deliveries in a row: {reason}',
                'info: Tracewick resumed sending profiles after a successful trial delivery.',
            ],
            $logger->lines(),
        );
        self::assertSame([['pauseMs' => 1_500, 'failures' => 2, 'reason' => '2'], []], array_column($logger->records, 'context'));
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
