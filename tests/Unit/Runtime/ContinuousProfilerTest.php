<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Diagnostics\HostCounterKeys;
use Tracewick\Diagnostics\NullCounterStore;
use Tracewick\Exception\LogicException;
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
use Tracewick\Sampler\EventType;
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
use Tracewick\Sink\AggregationCardinalityLimiter;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\SpoolAggregationKey;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingLogger;
use Tracewick\Tests\Support\RecordingSink;
use Tracewick\Tests\Support\ScriptedSampler;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\Delivery;

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
#[UsesClass(NullSampler::class)]
#[UsesClass(NullSamplerReason::class)]
#[UsesClass(ExcimerSampler::class)]
#[UsesClass(ExcimerTraceConverter::class)]
#[UsesClass(StackAggregator::class)]
#[UsesClass(SamplerConfig::class)]
#[UsesClass(SamplerFactory::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(LogicException::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(NullCounterStore::class)]
#[UsesClass(HostCounterKeys::class)]
#[UsesClass(LabelPolicy::class)]
#[UsesClass(AggregationCardinalityLimiter::class)]
final class ContinuousProfilerTest extends TestCase
{
    private const WINDOW_NS = 15_000_000_000;

    private ScriptedSampler $sampler;

    private RecordingSink $sink;

    private ManualClock $clock;

    protected function setUp(): void
    {
        ContinuousProfiler::resetInactiveSamplerWarning();
        DiagnosticLogger::resetRateLimits();
        $this->sampler = new ScriptedSampler();
        $this->sink = new RecordingSink();
        $this->clock = new ManualClock(1_000_000_000);
    }

    public function testCapturesSegmentsIntoClosedWindows(): void
    {
        $this->sampler->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'work', 2));
        $this->sampler->willReturn($this->batch(3_000_000_000, 4_000_000_000, 'work', 3));
        $profiler = $this->profiler(new Labels(['env' => 'prod']));

        $profiler->start();
        $profiler->pulse();
        self::assertSame(1, $profiler->openBuckets());
        self::assertSame(0, $profiler->diagnostics()->deliveredProfiles);

        $this->clock->advanceNs(self::WINDOW_NS);
        $profiler->pulse();

        $sent = $this->sink->sent;
        self::assertCount(1, $sent);
        $delivered = $sent[0];
        self::assertNotEmpty($delivered->stacks);
        self::assertSame(5, $delivered->stacks[0]->count);
        self::assertSame(['env' => 'prod'], $delivered->labels->toArray());
        self::assertSame(2, $profiler->diagnostics()->collectedProfiles);
        self::assertSame(1, $profiler->diagnostics()->deliveredProfiles);
    }

    public function testStopFlushesOpenBuckets(): void
    {
        $this->sampler->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'work', 1));
        $profiler = $this->profiler();

        $profiler->start();
        $profiler->pulse();
        self::assertSame(0, $profiler->diagnostics()->deliveredProfiles);

        $profiler->stop();

        self::assertCount(1, $this->sink->sent);
        self::assertFalse($profiler->isRunning());
        self::assertSame(0, $profiler->openBuckets());
    }

    public function testDoesNotAcceptASecondStart(): void
    {
        $profiler = $this->profiler();
        $profiler->start();
        $profiler->start();

        self::assertSame(['flush', 'start'], $this->sampler->calls);
        self::assertTrue($profiler->isRunning());
    }

    public function testNeverThrowsWhenTheSamplerFails(): void
    {
        $this->sampler->willFail('start', new \RuntimeException('boom'));
        $profiler = $this->profiler();

        $profiler->start();

        self::assertFalse($profiler->isRunning());
        self::assertSame(1, $profiler->diagnostics()->swallowedErrors);
        self::assertSame('boom', $profiler->lastFailure()?->getMessage());
    }

    public function testStaysIdleWithANullSampler(): void
    {
        $profiler = new ContinuousProfiler(
            new NullSampler(NullSamplerReason::ExtensionMissing, clock: $this->clock),
            $this->sink,
            new ContinuousProfilerConfig('blog'),
            clock: $this->clock,
        );

        $profiler->start();
        $profiler->pulse();
        $profiler->stop();

        self::assertSame([], $this->sink->sent);
        self::assertSame(NullSamplerReason::ExtensionMissing, $profiler->diagnostics()->inactiveSampler);
    }

    public function testCreatePicksTheAvailableSampler(): void
    {
        $profiler = ContinuousProfiler::create($this->sink, new ContinuousProfilerConfig('blog'));

        $profiler->start();
        self::assertTrue($profiler->isRunning());
        $profiler->stop();

        self::assertFalse($profiler->isRunning());
        self::assertNull($profiler->lastFailure());
    }

    public function testWiresAnExcimerSegmentListenerWhenAvailable(): void
    {
        if (!\extension_loaded('excimer')) {
            self::markTestSkipped('ext-excimer is not loaded.');
        }

        // Do not busy-wait for native flush here: under pcov that path has segfaulted in CI.
        // Flag-only delivery is covered by testSegmentListenerOnlyMarksPendingWithoutDelivering;
        // real Excimer segment behaviour lives in Integration/Sampler/ExcimerSamplerTest.
        $sampler = new ExcimerSampler(new SamplerConfig());
        $profiler = new ContinuousProfiler(
            $sampler,
            $this->sink,
            new ContinuousProfilerConfig('blog'),
            clock: $this->clock,
        );

        $profiler->start();
        self::assertFalse($profiler->hasPendingSegment());
        $profiler->pulse();
        $profiler->stop();

        self::assertFalse($profiler->isRunning());
        self::assertFalse($profiler->hasPendingSegment());
    }

    public function testSegmentListenerOnlyMarksPendingWithoutDelivering(): void
    {
        $profiler = $this->profiler();
        $profiler->start();

        $onSegment = new \ReflectionMethod(ContinuousProfiler::class, 'onSegment');
        $onSegment->invoke($profiler);

        self::assertTrue($profiler->hasPendingSegment());
        self::assertSame([], $this->sink->sent);
        self::assertSame(0, $profiler->diagnostics()->deliveredProfiles);

        $profiler->flush();
        self::assertFalse($profiler->hasPendingSegment());
    }

    public function testRegisterShutdownStopsOnce(): void
    {
        $calls = 0;
        $profiler = new ContinuousProfiler(
            $this->sampler,
            $this->sink,
            new ContinuousProfilerConfig('blog'),
            clock: $this->clock,
            registerShutdownFunction: static function (callable $callback) use (&$calls): void {
                ++$calls;
                $callback();
            },
        );

        $profiler->registerShutdown();
        $profiler->registerShutdown();

        self::assertSame(1, $calls);
    }

    public function testConfigRejectsAnInvalidApplicationName(): void
    {
        $this->expectException(LogicException::class);
        new ContinuousProfilerConfig('bad name');
    }

    public function testFlushSendsClosedWindowsWithoutStopping(): void
    {
        $this->sampler->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'work', 1));
        $profiler = $this->profiler();

        $profiler->start();
        $this->clock->advanceNs(self::WINDOW_NS);
        $profiler->flush();

        self::assertTrue($profiler->isRunning());
        self::assertCount(1, $this->sink->sent);
        self::assertSame(1, $profiler->diagnostics()->deliveredProfiles);
    }

    public function testStartSwallowsAQuietSamplerFailure(): void
    {
        $this->sampler->willFailQuietly('start', new \RuntimeException('timer'));
        $profiler = $this->profiler();

        $profiler->start();

        self::assertFalse($profiler->isRunning());
        self::assertSame(1, $profiler->diagnostics()->swallowedErrors);
        self::assertSame('timer', $profiler->lastFailure()?->getMessage());
    }

    public function testPulseIsIgnoredWhileAlreadyPulsing(): void
    {
        $this->sampler->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'work', 1));
        $this->clock->advanceNs(self::WINDOW_NS);
        $profiler = $this->profiler();
        $profiler->start();
        $this->sink->onSend[] = static function () use ($profiler): void {
            $profiler->pulse();
        };

        $profiler->pulse();

        self::assertCount(1, $this->sink->sent);
    }

    public function testPulseSwallowsSamplerFailures(): void
    {
        $profiler = $this->profiler();
        $profiler->start();
        $this->sampler->willFail('flush', new \RuntimeException('flush boom'));

        $profiler->pulse();

        self::assertSame(1, $profiler->diagnostics()->swallowedErrors);
        self::assertSame('flush boom', $profiler->lastFailure()?->getMessage());
    }

    public function testFlushSwallowsFailures(): void
    {
        $profiler = $this->profiler();
        $profiler->start();
        $this->sampler->willFail('flush', new \RuntimeException('flush boom'));

        $profiler->flush();

        self::assertSame(1, $profiler->diagnostics()->swallowedErrors);
    }

    public function testStopSwallowsFailures(): void
    {
        $profiler = $this->profiler();
        $profiler->start();
        $this->sampler->willFail('stop', new \RuntimeException('stop boom'));

        $profiler->stop();

        self::assertFalse($profiler->isRunning());
        self::assertSame(1, $profiler->diagnostics()->swallowedErrors);
        self::assertSame('stop boom', $profiler->lastFailure()?->getMessage());
    }

    public function testRegisterShutdownSwallowsRegistrationFailures(): void
    {
        $profiler = new ContinuousProfiler(
            $this->sampler,
            $this->sink,
            new ContinuousProfilerConfig('blog'),
            clock: $this->clock,
            registerShutdownFunction: static function (): never {
                throw new \RuntimeException('cannot register');
            },
        );

        $profiler->registerShutdown();

        self::assertSame(1, $profiler->diagnostics()->swallowedErrors);
        self::assertSame('cannot register', $profiler->lastFailure()?->getMessage());
    }

    public function testRegisterShutdownUsesPhpWhenNoCallbackGiven(): void
    {
        $profiler = new ContinuousProfiler(
            $this->sampler,
            $this->sink,
            new ContinuousProfilerConfig('blog'),
            clock: $this->clock,
        );

        $profiler->registerShutdown();

        self::assertSame(0, $profiler->diagnostics()->swallowedErrors);
    }

    public function testRecordsDeliveryOutcomes(): void
    {
        $this->sink->deliveries = [
            Delivery::rejected('bad'),
            Delivery::configuration('auth'),
            Delivery::failed('down'),
            Delivery::skipped('breaker'),
            Delivery::empty('No samples.'),
        ];
        $this->sampler
            ->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'a', 1))
            ->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'b', 1))
            ->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'c', 1))
            ->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'd', 1))
            ->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'e', 1));
        $profiler = $this->profiler();
        $profiler->start();
        $this->clock->advanceNs(self::WINDOW_NS);

        for ($i = 0; $i < 5; ++$i) {
            $profiler->flush();
            $this->clock->advanceNs(self::WINDOW_NS);
        }

        $diagnostics = $profiler->diagnostics();
        self::assertSame(2, $diagnostics->rejectedProfiles);
        self::assertSame(1, $diagnostics->failedProfiles);
        self::assertSame(1, $diagnostics->skippedProfiles);
        self::assertSame(1, $diagnostics->emptyProfiles);
        self::assertCount(5, $this->sink->sent);
    }

    public function testCountsEmptyCapturesWithoutSending(): void
    {
        $this->sampler->willReturn(new SampleBatch(
            EventType::Wall,
            10_000_000,
            1_000_000_000,
            2_000_000_000,
            [],
        ));
        $profiler = $this->profiler();
        $profiler->start();
        $profiler->pulse();

        self::assertSame(1, $profiler->diagnostics()->emptyProfiles);
        self::assertSame([], $this->sink->sent);
    }

    public function testDropsDeniedProcessLabelsOnce(): void
    {
        $logger = new RecordingLogger();
        $directory = new TemporaryDirectory();
        try {
            ContinuousProfiler::resetInactiveSamplerWarning();
            $profiler = new ContinuousProfiler(
                $this->sampler,
                $this->sink,
                new ContinuousProfilerConfig('blog', new Labels(['email' => 'user@example.com', 'env' => 'prod'])),
                new DiagnosticLogger($logger, $directory->path, clock: $this->clock),
                $this->clock,
            );
            new ContinuousProfiler(
                $this->sampler,
                $this->sink,
                new ContinuousProfilerConfig('blog', new Labels(['email' => 'other@example.com'])),
                new DiagnosticLogger($logger, $directory->path, clock: $this->clock),
                $this->clock,
            );

            self::assertCount(1, array_filter(
                $logger->records,
                static fn(array $r): bool => str_contains($r['message'], 'denied by the label policy'),
            ));
            self::assertNull($profiler->diagnostics()->inactiveSampler);
        } finally {
            $directory->remove();
        }
    }

    public function testSwallowsSinkExceptionsDuringDelivery(): void
    {
        $sink = new RecordingSink(failOn: [0]);
        $this->sampler->willReturn($this->batch(1_000_000_000, 2_000_000_000, 'work', 1));
        $profiler = new ContinuousProfiler(
            $this->sampler,
            $sink,
            new ContinuousProfilerConfig('blog'),
            clock: $this->clock,
        );
        $profiler->start();
        $this->clock->advanceNs(self::WINDOW_NS);
        $profiler->flush();

        self::assertSame(1, $profiler->diagnostics()->swallowedErrors);
        self::assertSame('Send 0 failed.', $profiler->lastFailure()?->getMessage());
    }

    public function testWarnsAboutDisabledAndFailedSamplers(): void
    {
        $logger = new RecordingLogger();
        $directory = new TemporaryDirectory();
        try {
            ContinuousProfiler::resetInactiveSamplerWarning();
            new ContinuousProfiler(
                new NullSampler(NullSamplerReason::Disabled, clock: $this->clock),
                $this->sink,
                new ContinuousProfilerConfig('blog'),
                new DiagnosticLogger($logger, $directory->path, clock: $this->clock),
                $this->clock,
            );
            self::assertSame(['warning: Tracewick profiling is disabled (TRACEWICK_ENABLED).'], $logger->lines());

            ContinuousProfiler::resetInactiveSamplerWarning();
            new ContinuousProfiler(
                new NullSampler(
                    NullSamplerReason::InitializationFailed,
                    cause: new \RuntimeException('Cannot create timer.'),
                    clock: $this->clock,
                ),
                $this->sink,
                new ContinuousProfilerConfig('blog'),
                new DiagnosticLogger($logger, $directory->path, clock: $this->clock),
                $this->clock,
            );
            self::assertContains(
                'warning: Tracewick profiling is inactive: ExcimerProfiler failed to initialize.',
                $logger->lines(),
            );
            $errors = array_map(
                static fn(array $record): mixed => $record['context']['error'] ?? null,
                $logger->records,
            );
            self::assertContains('Cannot create timer.', $errors);
        } finally {
            $directory->remove();
        }
    }

    private function profiler(Labels $labels = new Labels()): ContinuousProfiler
    {
        return new ContinuousProfiler(
            $this->sampler,
            $this->sink,
            new ContinuousProfilerConfig('blog', $labels),
            clock: $this->clock,
        );
    }

    private function batch(int $startNs, int $endNs, string $function, int $count): SampleBatch
    {
        return new SampleBatch(
            EventType::Wall,
            10_000_000,
            $startNs,
            $endNs,
            [new Sample([new Frame($function)], $count, $startNs)],
        );
    }

}
