<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Transport\CircuitBreakerConfig;

#[CoversClass(CircuitBreakerConfig::class)]
final class CircuitBreakerConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new CircuitBreakerConfig();

        self::assertSame(3, $config->failureThreshold);
        self::assertSame(30_000, $config->openDurationMs);
    }

    public function testAcceptsTheBounds(): void
    {
        self::assertSame(1, (new CircuitBreakerConfig(1, 1))->failureThreshold);
        self::assertSame(3_600_000, (new CircuitBreakerConfig(100, 3_600_000))->openDurationMs);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalid(): iterable
    {
        yield 'threshold zero' => [0, 1_000];
        yield 'threshold too high' => [101, 1_000];
        yield 'duration zero' => [3, 0];
        yield 'duration too long' => [3, 3_600_001];
    }

    #[DataProvider('invalid')]
    public function testRejectsValuesOutOfRange(int $threshold, int $durationMs): void
    {
        $this->expectException(LogicException::class);

        new CircuitBreakerConfig($threshold, $durationMs);
    }
}
