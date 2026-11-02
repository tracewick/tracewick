<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
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
use Tracewick\Profile\ProfileMerger;
use Tracewick\Profile\Stack;
use Tracewick\Runtime\ContinuousProfiler;
use Tracewick\Runtime\ContinuousProfilerConfig;
use Tracewick\Runtime\Diagnostics;
use Tracewick\Runtime\ProfileAccumulator;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\SpoolAggregationWindow;
use Tracewick\Sampler\ExcimerSampler;
use Tracewick\Sampler\ExcimerTraceConverter;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\StackAggregator;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\CardinalityGate;
use Tracewick\Sink\SpoolAggregationKey;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingSink;
use Tracewick\Transport\Delivery;

/**
 * Real Excimer flush → ContinuousProfiler listener marks pending and must not send.
 *
 * Unit tests must not race hasPendingSegment while the sampler is still running.
 */
#[CoversClass(ContinuousProfiler::class)]
#[UsesClass(ContinuousProfilerConfig::class)]
#[UsesClass(ProfileAccumulator::class)]
#[UsesClass(SpoolAggregationWindow::class)]
#[UsesClass(SpoolAggregationKey::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(ProfilerConfig::class)]
#[UsesClass(Diagnostics::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(ProfileMerger::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
#[UsesClass(ExcimerSampler::class)]
#[UsesClass(ExcimerTraceConverter::class)]
#[UsesClass(StackAggregator::class)]
#[UsesClass(SamplerConfig::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(NullCounterStore::class)]
#[UsesClass(HostCounterKeys::class)]
#[UsesClass(LabelPolicy::class)]
#[UsesClass(CardinalityGate::class)]
#[RequiresPhpExtension('excimer')]
final class ContinuousProfilerTest extends TestCase
{
    private const PERIOD_NS = 1_000_000;

    public function testNativeSegmentListenerMarksPendingWithoutSending(): void
    {
        $sink = new RecordingSink();
        $clock = new ManualClock(1_000_000_000);
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS, maxSamples: 1));
        $profiler = new ContinuousProfiler(
            $sampler,
            $sink,
            new ContinuousProfilerConfig('blog'),
            clock: $clock,
        );

        $profiler->start();
        $this->busyUntil(static fn(): bool => $profiler->hasPendingSegment());

        self::assertTrue($profiler->hasPendingSegment());
        self::assertSame([], $sink->sent);
        self::assertSame(0, $profiler->diagnostics()->deliveredProfiles);

        $profiler->stop();
        self::assertFalse($profiler->isRunning());
    }

    /**
     * @param callable(): bool $predicate
     */
    private function busyUntil(callable $predicate, int $timeoutNs = 200_000_000): void
    {
        $deadline = hrtime(true) + $timeoutNs;
        while (hrtime(true) < $deadline) {
            if ($predicate()) {
                return;
            }
        }

        self::fail('Timed out waiting for Excimer segment callback.');
    }
}
