<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
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
use Tracewick\Tests\Support\RecordingSink;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\Delivery;

#[CoversClass(UnitOfWorkProfiler::class)]
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
    #[TestWith([true])]
    #[TestWith([false])]
    public function testNativeSegmentsWaitForApplicationRotationOrShutdown(bool $rotate): void
    {
        $clock = new ManualClock(1_000_000_000);
        $sink = new RecordingSink();
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: 1_000_000, maxSamples: 1));
        $segments = 0;
        $sampler->setSegmentListener(static function () use (&$segments): void {
            ++$segments;
        });
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
            while ($segments < 3 && hrtime(true) < $deadline) {
                // Exercise real native flushes after the rotation deadline.
            }

            self::assertGreaterThanOrEqual(3, $segments);
            self::assertTrue($profiler->isRunning());
            self::assertSame(0, $profiler->bufferedProfiles());
            self::assertSame(0, $profiler->diagnostics()->collectedProfiles);
            $profiler->flush();
            self::assertSame([], $sink->functions());

            if ($rotate) {
                $profiler->pulse();
                self::assertTrue($profiler->isRunning());
                self::assertSame(1, $profiler->bufferedProfiles());
                self::assertSame([], $sink->functions());
                $profiler->flush();
                self::assertCount(1, $sink->sent);
            } else {
                $profiler->shutdown();
                self::assertFalse($profiler->isRunning());
                self::assertCount(1, $sink->sent);
            }

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
