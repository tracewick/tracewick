<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingLogger;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\CircuitBreakerAttempt;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\FallbackCircuitBreakerStore;
use Tracewick\Transport\FileCircuitBreakerStore;
use Tracewick\Transport\InMemoryCircuitBreakerStore;

#[CoversClass(FallbackCircuitBreakerStore::class)]
#[UsesClass(FileCircuitBreakerStore::class)]
#[UsesClass(InMemoryCircuitBreakerStore::class)]
#[UsesClass(CircuitBreakerState::class)]
#[UsesClass(CircuitBreakerAttempt::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(SystemClock::class)]
final class FallbackCircuitBreakerStoreTest extends TestCase
{
    protected function setUp(): void
    {
        DiagnosticLogger::resetRateLimits();
    }

    public function testUsesTheFileStoreWhenItPersists(): void
    {
        $directory = new TemporaryDirectory();

        try {
            $store = FallbackCircuitBreakerStore::forDestination($directory->path, 'http://pyroscope');
            $attempt = $store->acquire(0, 1_000, 5_000);
            self::assertInstanceOf(CircuitBreakerAttempt::class, $attempt);
            $store->complete($attempt->generation, new CircuitBreakerState(2, 10, $attempt->generation));

            self::assertEquals(new CircuitBreakerState(2, 10, $attempt->generation), $store->load());
            self::assertEquals(
                new CircuitBreakerState(2, 10, $attempt->generation),
                FileCircuitBreakerStore::forDestination($directory->path, 'http://pyroscope')->load(),
            );
        } finally {
            $directory->remove();
        }
    }

    public function testFallsBackToMemoryWhenTheFileCannotBeWritten(): void
    {
        $directory = new TemporaryDirectory();
        $logger = new RecordingLogger();

        try {
            file_put_contents($directory->path . '/blocked', 'x');
            $file = new FileCircuitBreakerStore($directory->path . '/blocked/state');
            $store = new FallbackCircuitBreakerStore(
                $file,
                new DiagnosticLogger($logger, $directory->path . '/logs', clock: new ManualClock()),
            );

            $attempt = $store->acquire(0, 1_000, 5_000);
            self::assertInstanceOf(CircuitBreakerAttempt::class, $attempt);
            self::assertTrue($store->complete($attempt->generation, new CircuitBreakerState(4, null, $attempt->generation)));

            self::assertEquals(new CircuitBreakerState(4, null, $attempt->generation), $store->load());
            self::assertEquals(new CircuitBreakerState(), $file->load());
            self::assertSame(
                ['warning: Tracewick could not persist the circuit breaker state to disk; using in-memory state for this process.'],
                $logger->lines(),
            );

            $next = $store->acquire(0, 1_000, 5_000);
            self::assertInstanceOf(CircuitBreakerAttempt::class, $next);
            $store->complete($next->generation, new CircuitBreakerState(5, null, $next->generation));
            self::assertEquals(new CircuitBreakerState(5, null, $next->generation), $store->load());
            self::assertSame(
                ['warning: Tracewick could not persist the circuit breaker state to disk; using in-memory state for this process.'],
                $logger->lines(),
            );
        } finally {
            $directory->remove();
        }
    }

    public function testAcquireReturnsNullWhenTheSharedFileSaysOpen(): void
    {
        $directory = new TemporaryDirectory();

        try {
            $file = FileCircuitBreakerStore::forDestination($directory->path, 'http://pyroscope');
            $seed = $file->acquire(0, 1_000, 5_000);
            self::assertInstanceOf(CircuitBreakerAttempt::class, $seed);
            $file->complete($seed->generation, new CircuitBreakerState(3, 10_000_000_000, $seed->generation));

            $store = new FallbackCircuitBreakerStore($file);
            self::assertNull($store->acquire(0, 1_000, 5_000));
            self::assertFalse($store->complete($seed->generation - 1, new CircuitBreakerState()));
        } finally {
            $directory->remove();
        }
    }
}
