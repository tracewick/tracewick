<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Exception\LogicException;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\CircuitBreakerAttempt;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\FallbackCircuitBreakerStore;
use Tracewick\Transport\FileCircuitBreakerStore;
use Tracewick\Transport\InMemoryCircuitBreakerStore;

#[CoversClass(CircuitBreakerConfig::class)]
#[UsesClass(FileCircuitBreakerStore::class)]
#[UsesClass(FallbackCircuitBreakerStore::class)]
#[UsesClass(InMemoryCircuitBreakerStore::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(CircuitBreakerState::class)]
#[UsesClass(CircuitBreakerAttempt::class)]
final class CircuitBreakerConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new CircuitBreakerConfig();

        self::assertSame(3, $config->failureThreshold);
        self::assertSame(30_000, $config->openDurationMs);
        self::assertSame(300_000, $config->configurationOpenDurationMs);
        self::assertNull($config->stateDirectory);
    }

    public function testStoreForDefaultsToInMemory(): void
    {
        $store = (new CircuitBreakerConfig())->storeFor('http://pyroscope');

        self::assertInstanceOf(InMemoryCircuitBreakerStore::class, $store);
        $destination = 'http://pyroscope/' . bin2hex(random_bytes(8));
        $path = sys_get_temp_dir() . '/tracewick-circuit-' . md5($destination);
        (new CircuitBreakerConfig())->storeFor($destination)->acquire(0, 1_000, 5_000);
        self::assertFileDoesNotExist($path);
    }

    public function testStoreForUsesASharedFileWhenADirectoryIsSet(): void
    {
        $directory = new TemporaryDirectory();

        try {
            $store = (new CircuitBreakerConfig(stateDirectory: $directory->path))->storeFor('http://pyroscope');
            $attempt = $store->acquire(0, 1_000, 5_000);
            self::assertInstanceOf(CircuitBreakerAttempt::class, $attempt);
            $store->complete($attempt->generation, new CircuitBreakerState(1, null, $attempt->generation));

            self::assertContains('tracewick-circuit-' . md5('http://pyroscope'), $directory->entries());
        } finally {
            $directory->remove();
        }
    }

    public function testAcceptsTheBounds(): void
    {
        self::assertSame(1, (new CircuitBreakerConfig(1, 1))->failureThreshold);
        self::assertSame(3_600_000, (new CircuitBreakerConfig(100, 3_600_000))->openDurationMs);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalid(): iterable
    {
        yield 'threshold zero' => [0, 1_000, 1_000];
        yield 'threshold too high' => [101, 1_000, 1_000];
        yield 'duration zero' => [3, 0, 1_000];
        yield 'duration too long' => [3, 3_600_001, 1_000];
        yield 'configuration duration zero' => [3, 1_000, 0];
        yield 'configuration duration too long' => [3, 1_000, 3_600_001];
    }

    #[DataProvider('invalid')]
    public function testRejectsValuesOutOfRange(int $threshold, int $durationMs, int $configurationOpenDurationMs): void
    {
        $this->expectException(LogicException::class);

        new CircuitBreakerConfig($threshold, $durationMs, configurationOpenDurationMs: $configurationOpenDurationMs);
    }
}
