<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sampler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\SamplerConfig;

#[CoversClass(SamplerConfig::class)]
#[UsesClass(LogicException::class)]
final class SamplerConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new SamplerConfig();

        self::assertSame(10_000_000, $config->periodNs);
        self::assertSame(EventType::Wall, $config->eventType);
        self::assertSame(256, $config->maxDepth);
        self::assertSame(SamplerConfig::DEFAULT_MAX_SAMPLES, $config->maxSamples);
        self::assertSame(SamplerConfig::DEFAULT_MAX_UNIQUE_STACKS, $config->maxUniqueStacks);
        self::assertNull($config->projectRoot);
    }

    public function testNormalizesProjectRootTrailingSlash(): void
    {
        $config = new SamplerConfig(projectRoot: '/var/www/app/');

        self::assertSame('/var/www/app', $config->projectRoot);
    }

    public function testRejectsEmptyProjectRoot(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Project root must be a non-empty path when set.');

        new SamplerConfig(projectRoot: '   ');
    }

    public function testKeepsValidValues(): void
    {
        $config = new SamplerConfig(periodNs: 1_000_000, eventType: EventType::Cpu, maxDepth: 64, maxSamples: 100, maxUniqueStacks: 50);

        self::assertSame(1_000_000, $config->periodNs);
        self::assertSame(EventType::Cpu, $config->eventType);
        self::assertSame(64, $config->maxDepth);
        self::assertSame(100, $config->maxSamples);
        self::assertSame(50, $config->maxUniqueStacks);
    }

    public function testAcceptsBoundaryValues(): void
    {
        $shortest = new SamplerConfig(periodNs: SamplerConfig::MIN_PERIOD_NS, maxDepth: 1);
        $longest = new SamplerConfig(periodNs: SamplerConfig::MAX_PERIOD_NS, maxDepth: SamplerConfig::MAX_DEPTH_LIMIT);

        self::assertSame(100_000, $shortest->periodNs);
        self::assertSame(1, $shortest->maxDepth);
        self::assertSame(60_000_000_000, $longest->periodNs);
        self::assertSame(4096, $longest->maxDepth);
    }

    public function testPeriodInSeconds(): void
    {
        self::assertSame(0.01, (new SamplerConfig())->periodSeconds());
        self::assertSame(0.0001, (new SamplerConfig(periodNs: SamplerConfig::MIN_PERIOD_NS))->periodSeconds());
        self::assertSame(60.0, (new SamplerConfig(periodNs: SamplerConfig::MAX_PERIOD_NS))->periodSeconds());
    }

    #[DataProvider('invalidPeriods')]
    public function testRejectsPeriodOutOfRange(int $periodNs): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('Sampling period must be between 100000 and 60000000000 ns, got %d.', $periodNs));

        new SamplerConfig(periodNs: $periodNs);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidPeriods(): iterable
    {
        yield 'negative' => [-1];
        yield 'zero' => [0];
        yield 'just below minimum' => [SamplerConfig::MIN_PERIOD_NS - 1];
        yield 'just above maximum' => [SamplerConfig::MAX_PERIOD_NS + 1];
    }

    #[DataProvider('invalidDepths')]
    public function testRejectsDepthOutOfRange(int $maxDepth): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('Maximum stack depth must be between 1 and 4096, got %d.', $maxDepth));

        new SamplerConfig(maxDepth: $maxDepth);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidDepths(): iterable
    {
        yield 'negative' => [-1];
        yield 'zero means unlimited in Excimer and is not allowed' => [0];
        yield 'just above maximum' => [SamplerConfig::MAX_DEPTH_LIMIT + 1];
    }

    #[DataProvider('invalidMaxSamples')]
    public function testRejectsMaxSamplesOutOfRange(int $maxSamples): void
    {
        $this->expectException(LogicException::class);

        new SamplerConfig(maxSamples: $maxSamples);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidMaxSamples(): iterable
    {
        yield 'zero' => [0];
        yield 'above maximum' => [SamplerConfig::MAX_MAX_SAMPLES + 1];
    }

    #[DataProvider('invalidMaxUniqueStacks')]
    public function testRejectsMaxUniqueStacksOutOfRange(int $maxUniqueStacks): void
    {
        $this->expectException(LogicException::class);

        new SamplerConfig(maxUniqueStacks: $maxUniqueStacks);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidMaxUniqueStacks(): iterable
    {
        yield 'zero' => [0];
        yield 'above maximum' => [SamplerConfig::MAX_MAX_UNIQUE_STACKS + 1];
    }
}
