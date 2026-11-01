<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\FallbackCircuitBreakerStore;
use Tracewick\Transport\FileCircuitBreakerStore;

#[CoversClass(CircuitBreakerConfig::class)]
#[UsesClass(FileCircuitBreakerStore::class)]
#[UsesClass(FallbackCircuitBreakerStore::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(CircuitBreakerState::class)]
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

    public function testSharesTheStateInTheConfiguredDirectory(): void
    {
        $directory = new TemporaryDirectory();

        try {
            (new CircuitBreakerConfig(stateDirectory: $directory->path))->sharedStore('http://pyroscope')->save(new CircuitBreakerState(1));

            self::assertContains('tracewick-circuit-' . md5('http://pyroscope'), $directory->entries());
        } finally {
            $directory->remove();
        }
    }

    public function testSharesTheStateInTheTemporaryDirectoryByDefault(): void
    {
        $destination = 'http://pyroscope/' . bin2hex(random_bytes(8));
        $path = sys_get_temp_dir() . '/tracewick-circuit-' . md5($destination);

        try {
            (new CircuitBreakerConfig())->sharedStore($destination)->save(new CircuitBreakerState(1));

            self::assertFileExists($path);
        } finally {
            @unlink($path);
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
