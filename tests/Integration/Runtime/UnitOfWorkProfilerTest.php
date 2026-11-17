<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\ArrayCounterStore;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Diagnostics\HostCounterKeys;
use Tracewick\Diagnostics\NullCounterStore;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Profile\LabelPolicy;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Runtime\Diagnostics;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\ExcimerSampler;
use Tracewick\Sampler\ExcimerTraceConverter;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\NullSamplerReason;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Sampler\StackAggregator;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingLogger;
use Tracewick\Tests\Support\RecordingSink;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\Delivery;

#[CoversClass(UnitOfWorkProfiler::class)]
#[UsesClass(ArrayCounterStore::class)]
#[UsesClass(ProfilerConfig::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Profile::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
#[UsesClass(SamplerConfig::class)]
#[UsesClass(SamplerFactory::class)]
#[UsesClass(NullSampler::class)]
#[UsesClass(NullSamplerReason::class)]
#[UsesClass(ExcimerSampler::class)]
#[UsesClass(ExcimerTraceConverter::class)]
#[UsesClass(StackAggregator::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Diagnostics::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(NullCounterStore::class)]
#[UsesClass(HostCounterKeys::class)]
#[UsesClass(LabelPolicy::class)]
#[RequiresPhpExtension('excimer')]
final class UnitOfWorkProfilerTest extends TestCase
{
    public function testAutomaticRotationDefersCountersAndOverflowLogging(): void
    {
        DiagnosticLogger::resetRateLimits();
        $clock = new ManualClock(1_000_000_000);
        $sink = new RecordingSink();
        $logger = new RecordingLogger();
        $counters = new ArrayCounterStore();
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: 1_000_000, maxSamples: 1));
        $profiler = new UnitOfWorkProfiler(
            $sampler,
            $sink,
            new ProfilerConfig(maxBufferedProfiles: 1, sampleRate: 1.0, maxProfileDurationSeconds: 1),
            logger: new DiagnosticLogger($logger),
            clock: $clock,
            counters: $counters,
        );
        try {
            $profiler->start(['route' => 'import']);
            for ($rotation = 1; $rotation <= 3; ++$rotation) {
                $clock->advanceNs(2_000_000_000);
                $deadline = hrtime(true) + 1_000_000_000;
                while ($profiler->diagnostics()->collectedProfiles < $rotation && hrtime(true) < $deadline) {
                }
                self::assertSame($rotation, $profiler->diagnostics()->collectedProfiles);
                self::assertSame(0, $counters->get(HostCounterKeys::PROFILES_COLLECTED));
                self::assertSame([], $logger->lines());
            }
            self::assertSame(2, $profiler->diagnostics()->droppedProfiles);
            self::assertSame(1, $profiler->bufferedProfiles());
            self::assertSame([], $sink->functions());
            $profiler->flush();
            self::assertSame(3, $counters->get(HostCounterKeys::PROFILES_COLLECTED));
            self::assertSame(2, $counters->get(HostCounterKeys::PROFILES_DROPPED));
            self::assertCount(1, $logger->records);
            self::assertSame(2, $logger->records[0]['context']['count'] ?? null);
            $profiler->flush();
            self::assertSame(3, $counters->get(HostCounterKeys::PROFILES_COLLECTED));
            self::assertCount(1, $sink->sent);
            self::assertCount(1, $logger->lines());
        } finally {
            $profiler->stop();
            $sampler->stop();
        }
    }

    public function testNativeSegmentsAutomaticallyRotateWithoutApplicationPulses(): void
    {
        $clock = new ManualClock(1_000_000_000);
        $sink = new RecordingSink();
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: 1_000_000, maxSamples: 1));
        $profiler = new UnitOfWorkProfiler(
            $sampler,
            $sink,
            new ProfilerConfig(sampleRate: 1.0, maxProfileDurationSeconds: 1),
            clock: $clock,
        );

        try {
            $profiler->start(['route' => 'import']);
            $clock->advanceNs(2_000_000_000);
            $deadline = hrtime(true) + 1_000_000_000;
            while ($profiler->bufferedProfiles() < 1 && hrtime(true) < $deadline) {
                // Exercise real native flushes after the rotation deadline.
            }

            self::assertTrue($profiler->isRunning());
            self::assertSame(1, $profiler->bufferedProfiles());
            self::assertSame([], $sink->functions());
            $profiler->shutdown();
            self::assertFalse($profiler->isRunning());
            self::assertNotEmpty($sink->sent);

            self::assertFalse($sink->sent[0]->isEmpty());
            self::assertSame(['route' => 'import'], $sink->sent[0]->labels->toArray());
            self::assertSame(0, $sampler->failureCount());
            self::assertSame(0, $profiler->diagnostics()->swallowedErrors);
        } finally {
            $profiler->stop();
            $sampler->stop();
        }
    }
}
