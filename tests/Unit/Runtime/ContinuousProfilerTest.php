<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Exception\LogicException;
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
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\SpoolAggregationKey;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingLogger;
use Tracewick\Tests\Support\RecordingSink;
use Tracewick\Tests\Support\ScriptedSampler;
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
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
#[UsesClass(NullSampler::class)]
#[UsesClass(SamplerConfig::class)]
#[UsesClass(SamplerFactory::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(LogicException::class)]
#[UsesClass(AtomicFile::class)]
final class ContinuousProfilerTest extends TestCase
{
    private const WINDOW_NS = 15_000_000_000;

    private ScriptedSampler $sampler;

    private RecordingSink $sink;

    private ManualClock $clock;

    protected function setUp(): void
    {
        ContinuousProfiler::resetInactiveSamplerWarning();
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

    public function testCreateUsesANullSamplerWithoutExcimer(): void
    {
        $logger = new RecordingLogger();
        $profiler = ContinuousProfiler::create(
            $this->sink,
            new ContinuousProfilerConfig('blog'),
            logger: $logger,
        );

        $profiler->start();
        $profiler->pulse();
        $profiler->stop();

        self::assertSame([], $this->sink->sent);
        self::assertNotNull($profiler->diagnostics()->inactiveSampler);
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
