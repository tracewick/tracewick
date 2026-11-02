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
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\FallbackCircuitBreakerStore;
use Tracewick\Transport\FileCircuitBreakerStore;
use Tracewick\Transport\InMemoryCircuitBreakerStore;

#[CoversClass(FallbackCircuitBreakerStore::class)]
#[UsesClass(FileCircuitBreakerStore::class)]
#[UsesClass(InMemoryCircuitBreakerStore::class)]
#[UsesClass(CircuitBreakerState::class)]
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
            $store->save(new CircuitBreakerState(2, 10));

            self::assertEquals(new CircuitBreakerState(2, 10), $store->load());
            self::assertEquals(
                new CircuitBreakerState(2, 10),
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
                new InMemoryCircuitBreakerStore(),
                new DiagnosticLogger($logger, $directory->path . '/logs', clock: new ManualClock()),
            );

            $store->save(new CircuitBreakerState(4, 20));

            self::assertEquals(new CircuitBreakerState(4, 20), $store->load());
            self::assertEquals(new CircuitBreakerState(), $file->load());
            self::assertSame(
                ['warning: Tracewick could not persist the circuit breaker state to disk; using in-memory state for this process.'],
                $logger->lines(),
            );

            $store->save(new CircuitBreakerState(5));
            self::assertEquals(new CircuitBreakerState(5), $store->load());
            self::assertSame(
                ['warning: Tracewick could not persist the circuit breaker state to disk; using in-memory state for this process.'],
                $logger->lines(),
            );

            $updated = $store->update(static fn(CircuitBreakerState $state): CircuitBreakerState => new CircuitBreakerState(6, 30));
            self::assertEquals(new CircuitBreakerState(6, 30), $updated);
            self::assertEquals(new CircuitBreakerState(6, 30), $store->load());
        } finally {
            $directory->remove();
        }
    }

    public function testUpdateUsesTheFileStoreWhenItPersists(): void
    {
        $directory = new TemporaryDirectory();

        try {
            $store = FallbackCircuitBreakerStore::forDestination($directory->path, 'http://pyroscope');
            $updated = $store->update(static fn(CircuitBreakerState $state): CircuitBreakerState => new CircuitBreakerState(3, 40));

            self::assertEquals(new CircuitBreakerState(3, 40), $updated);
            self::assertEquals(new CircuitBreakerState(3, 40), $store->load());
        } finally {
            $directory->remove();
        }
    }

    public function testUpdateFallsBackToMemoryWhenTheFileCannotBeWritten(): void
    {
        $directory = new TemporaryDirectory();
        $logger = new RecordingLogger();

        try {
            file_put_contents($directory->path . '/blocked', 'x');
            $file = new FileCircuitBreakerStore($directory->path . '/blocked/state');
            $store = new FallbackCircuitBreakerStore(
                $file,
                new InMemoryCircuitBreakerStore(),
                new DiagnosticLogger($logger, $directory->path . '/logs', clock: new ManualClock()),
            );

            $updated = $store->update(static fn(CircuitBreakerState $state): CircuitBreakerState => new CircuitBreakerState(7, 50));

            self::assertEquals(new CircuitBreakerState(7, 50), $updated);
            self::assertEquals(new CircuitBreakerState(7, 50), $store->load());
            self::assertEquals(new CircuitBreakerState(), $file->load());
            self::assertSame(
                ['warning: Tracewick could not persist the circuit breaker state to disk; using in-memory state for this process.'],
                $logger->lines(),
            );
        } finally {
            $directory->remove();
        }
    }
}
