<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\Sampler;

/**
 * Sampler that records the calls it receives and hands out scripted batches.
 *
 * Like a real sampler, it only collects while started: a flush returns the next scripted batch when
 * the sampler ran since the previous flush, and an empty batch otherwise. A scripted throwable is
 * thrown by the next call to the method it is scripted for.
 */
final class ScriptedSampler implements Sampler
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<SampleBatch> */
    private array $batches = [];

    /** @var array<string, \Throwable> */
    private array $failures = [];

    /** @var array<string, \Throwable> */
    private array $quietFailures = [];

    private bool $running = false;

    private bool $ranSinceFlush = false;

    private int $failureCount = 0;

    private ?\Throwable $lastFailure = null;

    /**
     * Makes the next flush return a batch with one sample of the given function.
     */
    public function willSample(string $function, int $count = 1): self
    {
        $this->batches[] = new SampleBatch(EventType::Wall, 10_000_000, 1_000, 2_000, [new Sample([new Frame($function)], $count, 1_500)]);

        return $this;
    }

    /**
     * Makes the next flush return this batch when the sampler has been running.
     */
    public function willReturn(SampleBatch $batch): self
    {
        $this->batches[] = $batch;

        return $this;
    }

    public function willFail(string $method, \Throwable $failure): self
    {
        $this->failures[$method] = $failure;

        return $this;
    }

    public function start(): void
    {
        $failures = $this->failureCount;
        $this->record('start');
        if ($this->failureCount > $failures) {
            return;
        }

        $this->running = true;
        $this->ranSinceFlush = true;
    }

    public function stop(): void
    {
        $this->record('stop');
        $this->running = false;
    }

    public function flush(): SampleBatch
    {
        $this->record('flush');
        $ran = $this->ranSinceFlush;
        $this->ranSinceFlush = $this->running;
        $batch = $ran ? array_shift($this->batches) : null;

        return $batch ?? new SampleBatch(EventType::Wall, 10_000_000, 1_000, 2_000);
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function failureCount(): int
    {
        return $this->failureCount;
    }

    public function lastFailure(): ?\Throwable
    {
        return $this->lastFailure;
    }

    /**
     * Makes the next call to the method fail without throwing, like ExcimerSampler.
     */
    public function willFailQuietly(string $method, \Throwable $failure): self
    {
        $this->quietFailures[$method] = $failure;

        return $this;
    }

    private function record(string $method): void
    {
        $this->calls[] = $method;
        $quiet = $this->quietFailures[$method] ?? null;
        if ($quiet instanceof \Throwable) {
            unset($this->quietFailures[$method]);
            ++$this->failureCount;
            $this->lastFailure = $quiet;

            return;
        }

        $failure = $this->failures[$method] ?? null;
        if ($failure instanceof \Throwable) {
            unset($this->failures[$method]);
            ++$this->failureCount;
            $this->lastFailure = $failure;

            throw $failure;
        }
    }
}
