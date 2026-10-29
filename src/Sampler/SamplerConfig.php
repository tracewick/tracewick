<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

use Tracewick\Exception\LogicException;

/**
 * Validated sampler settings.
 *
 * Invalid values throw immediately: configuration is built at boot or in tests, where a clear
 * error is more useful than a profiler that silently collects nothing.
 */
final readonly class SamplerConfig
{
    /**
     * 10 ms, a common default for continuous profiling with low overhead.
     */
    public const DEFAULT_PERIOD_NS = 10_000_000;

    /**
     * 0.1 ms. Shorter periods make the timer signal itself a dominant cost.
     */
    public const MIN_PERIOD_NS = 100_000;

    /**
     * 60 s. Longer periods produce too few samples to be useful.
     */
    public const MAX_PERIOD_NS = 60_000_000_000;

    /**
     * Deep enough for framework stacks; deeper stacks are cut at the caller side.
     */
    public const DEFAULT_MAX_DEPTH = 256;

    /**
     * Upper bound that keeps the memory cost of a single sample bounded.
     */
    public const MAX_DEPTH_LIMIT = 4096;

    /**
     * @param int       $periodNs  time between two samples, in nanoseconds
     * @param EventType $eventType whether the period is measured in wall-clock or CPU time
     * @param int       $maxDepth  maximum number of frames recorded per sample, counted from the
     *                             innermost frame; callers beyond it are dropped
     */
    public function __construct(
        public int $periodNs = self::DEFAULT_PERIOD_NS,
        public EventType $eventType = EventType::Wall,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ) {
        if ($periodNs < self::MIN_PERIOD_NS || $periodNs > self::MAX_PERIOD_NS) {
            throw new LogicException(\sprintf(
                'Sampling period must be between %d and %d ns, got %d.',
                self::MIN_PERIOD_NS,
                self::MAX_PERIOD_NS,
                $periodNs,
            ));
        }

        if ($maxDepth < 1 || $maxDepth > self::MAX_DEPTH_LIMIT) {
            throw new LogicException(\sprintf(
                'Maximum stack depth must be between 1 and %d, got %d.',
                self::MAX_DEPTH_LIMIT,
                $maxDepth,
            ));
        }
    }
}
