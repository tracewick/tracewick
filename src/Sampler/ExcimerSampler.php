<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * Sampler backed by `ExcimerProfiler` from ext-excimer.
 *
 * The constructor requires the extension; use {@see SamplerFactory} to fall back to a
 * {@see NullSampler} when it is missing. After construction no method throws: failures are counted
 * and the last one is kept for diagnostics.
 *
 * Samples are aggregated by stack as the native flush callback drains the Excimer log, so memory
 * stays proportional to unique stacks rather than to the number of timer hits.
 */
final class ExcimerSampler implements Sampler
{
    private readonly Clock $clock;

    private readonly \ExcimerProfiler $profiler;

    private readonly ExcimerTraceConverter $converter;

    private readonly StackAggregator $aggregator;

    private bool $running = false;

    /**
     * Unix time in nanoseconds at which the current batch window opened, null while no window is
     * open (before the first start and after a flush of a stopped sampler).
     */
    private ?int $windowStartNs = null;

    private int $failureCount = 0;

    private ?\Throwable $lastFailure = null;

    /** @var ?\Closure(): void */
    private ?\Closure $segmentListener = null;

    public function __construct(
        private readonly SamplerConfig $config,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->aggregator = new StackAggregator($config->maxUniqueStacks);

        $this->profiler = new \ExcimerProfiler();
        $originNs = $this->clock->nowNs();

        $this->profiler->setPeriod($config->periodSeconds());
        $this->profiler->setEventType(match ($config->eventType) {
            EventType::Wall => EXCIMER_REAL,
            EventType::Cpu => EXCIMER_CPU,
        });
        $this->profiler->setMaxDepth($config->maxDepth);
        $this->profiler->setFlushCallback($this->onNativeFlush(...), $config->maxSamples);

        $this->converter = new ExcimerTraceConverter($originNs, $config->maxDepth);
    }

    /**
     * Invoked after each native Excimer flush has been ingested, for timed profile rotation.
     *
     * @param ?\Closure(): void $listener
     */
    public function setSegmentListener(?\Closure $listener): void
    {
        $this->segmentListener = $listener;
    }

    public function start(): void
    {
        // ExcimerProfiler::start() is documented to stop and restart a running profiler. Whether
        // that resets the timer is up to the extension, so a running sampler leaves it alone.
        if ($this->running) {
            return;
        }

        try {
            $this->windowStartNs ??= $this->clock->nowNs();
            $this->profiler->start();
            $this->running = true;
        } catch (\Throwable $e) {
            $this->recordFailure($e);
        }
    }

    public function stop(): void
    {
        // Stopping a stopped ExcimerProfiler is harmless, so no guard is needed here.
        $this->running = false;

        try {
            $this->profiler->stop();
        } catch (\Throwable $e) {
            $this->recordFailure($e);
        }
    }

    public function flush(): SampleBatch
    {
        try {
            $this->converter->ingestLog($this->profiler->flush(), $this->aggregator);
        } catch (\Throwable $e) {
            $this->recordFailure($e);
        }

        $nowNs = $this->clock->nowNs();
        $batch = new SampleBatch(
            $this->config->eventType,
            $this->config->periodNs,
            $this->windowStartNs ?? $nowNs,
            $nowNs,
            $this->aggregator->toSamples($this->windowStartNs ?? $nowNs),
        );
        $this->aggregator->reset();
        $this->windowStartNs = $this->running ? $nowNs : null;

        return $batch;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Number of failures swallowed since construction.
     */
    public function failureCount(): int
    {
        return $this->failureCount;
    }

    public function lastFailure(): ?\Throwable
    {
        return $this->lastFailure;
    }

    private function onNativeFlush(\ExcimerLog $log): void
    {
        try {
            $this->converter->ingestLog($log, $this->aggregator);
        } catch (\Throwable $e) {
            $this->recordFailure($e);

            return;
        }

        if (null !== $this->segmentListener) {
            try {
                ($this->segmentListener)();
            } catch (\Throwable $e) {
                $this->recordFailure($e);
            }
        }
    }

    private function recordFailure(\Throwable $e): void
    {
        ++$this->failureCount;
        $this->lastFailure = $e;
    }
}
