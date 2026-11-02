<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Sampler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\ExcimerSampler;
use Tracewick\Sampler\ExcimerTraceConverter;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Sampler\StackAggregator;
use Tracewick\Tests\Support\SteppingClock;

#[CoversClass(ExcimerSampler::class)]
#[CoversClass(ExcimerTraceConverter::class)]
#[CoversClass(SamplerFactory::class)]
#[UsesClass(SamplerConfig::class)]
#[UsesClass(SampleBatch::class)]
#[UsesClass(Sample::class)]
#[UsesClass(Frame::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(StackAggregator::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[RequiresPhpExtension('excimer')]
final class ExcimerSamplerTest extends TestCase
{
    private const PERIOD_NS = 1_000_000;

    /**
     * Excimer timestamps an event when its timer fires, but PHP records it only at the next
     * interrupt check. An event that fired just before a flush can therefore land in the next
     * batch with a timestamp slightly before that batch opened.
     */
    private const DELIVERY_SLACK_NS = 10 * self::PERIOD_NS;

    public function testFactoryCreatesExcimerSamplerWhenExtensionIsLoaded(): void
    {
        self::assertInstanceOf(ExcimerSampler::class, (new SamplerFactory([]))->create());
    }

    public function testCollectsWallClockSamples(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS));

        $before = (new SystemClock())->nowNs();
        $sampler->start();
        self::assertTrue($sampler->isRunning());
        self::busyWait(50_000_000);
        $sampler->stop();
        self::assertFalse($sampler->isRunning());

        $batch = $sampler->flush();

        self::assertSame(EventType::Wall, $batch->eventType);
        self::assertSame(self::PERIOD_NS, $batch->periodNs);
        self::assertGreaterThan(10, $batch->eventCount());
        self::assertGreaterThanOrEqual($before, $batch->startNs);
        self::assertGreaterThan($batch->startNs, $batch->endNs);
        self::assertSame(0, $sampler->failureCount());
        self::assertNull($sampler->lastFailure());

        $names = [];
        foreach ($batch->samples as $sample) {
            self::assertGreaterThanOrEqual($batch->startNs - self::DELIVERY_SLACK_NS, $sample->timestampNs);
            self::assertLessThanOrEqual($batch->endNs, $sample->timestampNs);
            foreach ($sample->frames as $frame) {
                $names[$frame->name()] = true;
            }
        }

        self::assertArrayHasKey(self::class . '::busyWait', $names);
    }

    public function testCollectsCpuSamples(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS, eventType: EventType::Cpu));

        $sampler->start();
        self::busyWait(50_000_000);
        $sampler->stop();

        $batch = $sampler->flush();

        self::assertSame(EventType::Cpu, $batch->eventType);
        self::assertGreaterThan(0, $batch->eventCount());
    }

    public function testLimitsStackDepth(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS, maxDepth: 3));

        $sampler->start();
        self::recurse(20);
        $sampler->stop();

        $batch = $sampler->flush();

        self::assertFalse($batch->isEmpty());
        $truncated = 0;
        foreach ($batch->samples as $sample) {
            self::assertLessThanOrEqual(3, \count($sample->frames), implode(' > ', array_map(
                static fn(Frame $frame): string => $frame->name(),
                $sample->frames,
            )));
            $truncated += $sample->truncated ? 1 : 0;
        }

        self::assertGreaterThan(0, $truncated);
    }

    public function testFlushStartsANewBatchWhileRunning(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS));

        $sampler->start();
        self::busyWait(20_000_000);
        $first = $sampler->flush();
        self::assertTrue($sampler->isRunning());
        self::busyWait(20_000_000);
        $sampler->stop();
        $second = $sampler->flush();

        self::assertFalse($first->isEmpty());
        self::assertFalse($second->isEmpty());
        self::assertSame($first->endNs, $second->startNs);
        foreach ($second->samples as $sample) {
            self::assertGreaterThanOrEqual($first->endNs - self::DELIVERY_SLACK_NS, $sample->timestampNs);
        }
    }

    public function testFlushOfStoppedSamplerIsEmpty(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS));

        $neverStarted = $sampler->flush();

        $sampler->start();
        self::busyWait(10_000_000);
        $sampler->stop();
        $sampler->flush();
        $afterFlush = $sampler->flush();

        self::assertTrue($neverStarted->isEmpty());
        self::assertSame($neverStarted->startNs, $neverStarted->endNs);
        self::assertTrue($afterFlush->isEmpty());
        self::assertSame($afterFlush->startNs, $afterFlush->endNs);
    }

    public function testRepeatedStartAndStopAreHarmless(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS));

        $sampler->stop();
        $sampler->start();
        $sampler->start();
        self::busyWait(10_000_000);
        $sampler->stop();
        $sampler->stop();

        self::assertFalse($sampler->isRunning());
        self::assertFalse($sampler->flush()->isEmpty());
        self::assertSame(0, $sampler->failureCount());
    }

    public function testCpuModeIgnoresTimeSpentWaiting(): void
    {
        $wall = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS));
        $cpu = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS, eventType: EventType::Cpu));

        $wall->start();
        $cpu->start();
        usleep(100_000);
        $cpu->stop();
        $wall->stop();

        self::assertGreaterThan(20, $wall->flush()->eventCount());
        self::assertLessThan(20, $cpu->flush()->eventCount());
    }

    public function testRepeatedStartDoesNotResetTheTimer(): void
    {
        // A period longer than the interval between start() calls: if every call restarted the
        // timer, it would never fire.
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: 20_000_000));

        for ($i = 0; $i < 40; ++$i) {
            $sampler->start();
            self::busyWait(5_000_000);
        }

        $sampler->stop();

        self::assertGreaterThan(3, $sampler->flush()->eventCount());
    }

    public function testStopStopsSampling(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS));

        $sampler->start();
        self::busyWait(10_000_000);
        $sampler->stop();
        $sampler->flush();
        self::busyWait(30_000_000);

        self::assertTrue($sampler->flush()->isEmpty());
    }

    public function testUsesTheGivenClockForTheBatchWindow(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS), new SteppingClock(1_000, 10));

        // Construction reads 1000 as the origin of Excimer timestamps.
        $neverStarted = $sampler->flush();
        self::assertSame(1_010, $neverStarted->startNs);
        self::assertSame(1_010, $neverStarted->endNs);

        // The window opens at the first start (1020) and survives a stop and a second start.
        $sampler->start();
        $sampler->stop();
        $sampler->start();
        $sampler->stop();
        $batch = $sampler->flush();

        self::assertSame(1_020, $batch->startNs);
        self::assertSame(1_030, $batch->endNs);
    }

    public function testRecreatesTheProfilerAfterASimulatedFork(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS));
        $sampler->start();
        self::busyWait(10_000_000);
        $sampler->simulateForkForTests();
        self::busyWait(20_000_000);
        $sampler->stop();

        $batch = $sampler->flush();

        self::assertFalse($batch->isEmpty());
        self::assertSame(0, $sampler->failureCount());
    }

    public function testRecreatesARunningProfilerDuringFlushAfterASimulatedFork(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS));
        $sampler->start();
        self::busyWait(10_000_000);
        $sampler->simulateForkForTests();

        $batch = $sampler->flush();

        self::assertTrue($sampler->isRunning());
        self::assertFalse($batch->isEmpty());
        self::assertSame(0, $sampler->failureCount());

        $sampler->stop();
        $sampler->flush();
    }

    public function testNativeFlushCallbackFeedsTheAggregatorAndSegmentListener(): void
    {
        $hits = 0;
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS, maxSamples: 1));
        $sampler->setSegmentListener(static function () use (&$hits): void {
            ++$hits;
        });

        $sampler->start();
        self::busyWait(40_000_000);
        $sampler->stop();
        $batch = $sampler->flush();

        self::assertGreaterThan(0, $hits);
        self::assertFalse($batch->isEmpty());
        self::assertSame(0, $sampler->failureCount());
    }

    public function testSegmentListenerFailuresAreSwallowed(): void
    {
        $sampler = new ExcimerSampler(new SamplerConfig(periodNs: self::PERIOD_NS, maxSamples: 1));
        $sampler->setSegmentListener(static function (): never {
            throw new \RuntimeException('Listener failed.');
        });

        $sampler->start();
        self::busyWait(40_000_000);
        $sampler->stop();
        $sampler->flush();

        self::assertGreaterThan(0, $sampler->failureCount());
        self::assertSame('Listener failed.', $sampler->lastFailure()?->getMessage());
    }

    private static function busyWait(int $durationNs): void
    {
        $deadline = hrtime(true) + $durationNs;
        while (hrtime(true) < $deadline) {
            // Keep the CPU busy so that both wall-clock and CPU timers fire.
        }
    }

    private static function recurse(int $depth): void
    {
        if (0 === $depth) {
            self::busyWait(30_000_000);

            return;
        }

        self::recurse($depth - 1);
    }
}
