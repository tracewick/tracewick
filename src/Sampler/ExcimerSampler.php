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
 */
final class ExcimerSampler implements Sampler
{
    private readonly Clock $clock;

    private readonly \ExcimerProfiler $profiler;

    private readonly ExcimerTraceConverter $converter;

    private bool $running = false;

    /**
     * Unix time in nanoseconds at which the current batch window opened, null while no window is
     * open (before the first start and after a flush of a stopped sampler).
     */
    private ?int $windowStartNs = null;

    private int $failureCount = 0;

    private ?\Throwable $lastFailure = null;

    public function __construct(
        private readonly SamplerConfig $config,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();

        $this->profiler = new \ExcimerProfiler();
        $originNs = $this->clock->nowNs();

        $this->profiler->setPeriod($config->periodNs / 1_000_000_000);
        $this->profiler->setEventType(match ($config->eventType) {
            EventType::Wall => EXCIMER_REAL,
            EventType::Cpu => EXCIMER_CPU,
        });
        $this->profiler->setMaxDepth($config->maxDepth);

        $this->converter = new ExcimerTraceConverter($originNs, $config->maxDepth);
    }

    public function start(): void
    {
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
        if (!$this->running) {
            return;
        }

        $this->running = false;

        try {
            $this->profiler->stop();
        } catch (\Throwable $e) {
            $this->recordFailure($e);
        }
    }

    public function flush(): SampleBatch
    {
        $samples = [];
        try {
            $samples = $this->converter->convertLog($this->profiler->flush());
        } catch (\Throwable $e) {
            $this->recordFailure($e);
        }

        $nowNs = $this->clock->nowNs();
        $batch = new SampleBatch(
            $this->config->eventType,
            $this->config->periodNs,
            $this->windowStartNs ?? $nowNs,
            $nowNs,
            $samples,
        );
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

    private function recordFailure(\Throwable $e): void
    {
        ++$this->failureCount;
        $this->lastFailure = $e;
    }
}
