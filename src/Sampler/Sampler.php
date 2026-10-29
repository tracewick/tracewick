<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

/**
 * Collects stack samples of the current PHP process.
 *
 * Implementations are used on runtime paths and must never throw: failures are swallowed and
 * reported through diagnostics, and the worst outcome for the host application is an empty batch.
 */
interface Sampler
{
    /**
     * Starts sampling. Calling it while sampling is already running has no effect.
     */
    public function start(): void;

    /**
     * Stops sampling. Samples collected so far are kept until the next flush.
     */
    public function stop(): void;

    /**
     * Returns the samples collected since the previous flush and forgets them.
     *
     * Sampling, if running, continues into the next batch.
     */
    public function flush(): SampleBatch;

    public function isRunning(): bool;
}
