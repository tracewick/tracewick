<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * Sampler that collects nothing, used when profiling is disabled or cannot work.
 *
 * It keeps the configured period and event type so that its empty batches still describe what
 * would have been collected.
 */
final class NullSampler implements Sampler
{
    private readonly Clock $clock;

    private int $windowStartNs;

    public function __construct(
        public readonly NullSamplerReason $reason,
        private readonly SamplerConfig $config = new SamplerConfig(),
        public readonly ?\Throwable $cause = null,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->windowStartNs = $this->clock->nowNs();
    }

    public function start(): void {}

    public function stop(): void {}

    public function flush(): SampleBatch
    {
        $now = $this->clock->nowNs();
        $batch = new SampleBatch($this->config->eventType, $this->config->periodNs, $this->windowStartNs, $now);
        $this->windowStartNs = $now;

        return $batch;
    }

    public function isRunning(): bool
    {
        return false;
    }

    public function failureCount(): int
    {
        return 0;
    }

    public function lastFailure(): ?\Throwable
    {
        return $this->cause;
    }
}
