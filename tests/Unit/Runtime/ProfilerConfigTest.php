<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;
use Tracewick\Runtime\ProfilerConfig;

#[CoversClass(ProfilerConfig::class)]
#[UsesClass(Labels::class)]
final class ProfilerConfigTest extends TestCase
{
    public function testUsesDefaults(): void
    {
        $config = new ProfilerConfig();

        self::assertTrue($config->labels->isEmpty());
        self::assertSame(ProfilerConfig::DEFAULT_MAX_BUFFERED_PROFILES, $config->maxBufferedProfiles);
        self::assertSame(1.0, $config->sampleRate);
        self::assertFalse($config->finishRequest);
    }

    #[TestWith([1])]
    #[TestWith([1_000])]
    public function testAcceptsBufferSizesWithinTheLimits(int $size): void
    {
        self::assertSame($size, (new ProfilerConfig(maxBufferedProfiles: $size))->maxBufferedProfiles);
    }

    #[TestWith([0])]
    #[TestWith([1_001])]
    public function testRejectsBufferSizesOutsideTheLimits(int $size): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('The number of buffered profiles must be between 1 and 1000, got %d.', $size));

        new ProfilerConfig(maxBufferedProfiles: $size);
    }

    #[TestWith([0.0])]
    #[TestWith([0.25])]
    #[TestWith([1.0])]
    public function testAcceptsSampleRatesFromZeroToOne(float $rate): void
    {
        self::assertSame($rate, (new ProfilerConfig(sampleRate: $rate))->sampleRate);
    }

    #[TestWith([-0.01, '-0.01'])]
    #[TestWith([1.01, '1.01'])]
    #[TestWith([\NAN, 'NAN'])]
    public function testRejectsSampleRatesOutsideZeroToOne(float $rate, string $shown): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('The sample rate must be between 0 and 1, got %s.', $shown));

        new ProfilerConfig(sampleRate: $rate);
    }
}
