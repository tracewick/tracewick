<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sampler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\NullSamplerReason;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Tests\Support\SteppingClock;

#[CoversClass(NullSampler::class)]
#[UsesClass(SamplerConfig::class)]
#[UsesClass(SampleBatch::class)]
#[UsesClass(SystemClock::class)]
final class NullSamplerTest extends TestCase
{
    public function testCollectsNothing(): void
    {
        $sampler = new NullSampler(NullSamplerReason::ExtensionMissing);

        $sampler->start();
        self::assertFalse($sampler->isRunning());
        $sampler->stop();

        $batch = $sampler->flush();
        self::assertTrue($batch->isEmpty());
        self::assertSame(EventType::Wall, $batch->eventType);
        self::assertSame(SamplerConfig::DEFAULT_PERIOD_NS, $batch->periodNs);
    }

    public function testKeepsReasonAndCause(): void
    {
        $cause = new \RuntimeException('boom');
        $sampler = new NullSampler(NullSamplerReason::InitializationFailed, new SamplerConfig(), $cause);

        self::assertSame(NullSamplerReason::InitializationFailed, $sampler->reason);
        self::assertSame($cause, $sampler->cause);
        self::assertSame(0, $sampler->failureCount());
        self::assertSame($cause, $sampler->lastFailure());
    }

    public function testReportsNoFailuresWithoutACause(): void
    {
        $sampler = new NullSampler(NullSamplerReason::Disabled);

        self::assertSame(0, $sampler->failureCount());
        self::assertNull($sampler->lastFailure());
    }

    public function testBatchesDescribeTheConfiguredSamplingAndFollowEachOther(): void
    {
        $config = new SamplerConfig(periodNs: 5_000_000, eventType: EventType::Cpu);
        $sampler = new NullSampler(NullSamplerReason::Disabled, $config, null, new SteppingClock(1_000, 10));

        $first = $sampler->flush();
        $second = $sampler->flush();

        self::assertSame(EventType::Cpu, $first->eventType);
        self::assertSame(5_000_000, $first->periodNs);
        self::assertSame(1_000, $first->startNs);
        self::assertSame(1_010, $first->endNs);
        self::assertSame(1_010, $second->startNs);
        self::assertSame(1_020, $second->endNs);
    }
}
