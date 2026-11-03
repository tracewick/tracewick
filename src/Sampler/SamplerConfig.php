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
     * Native Excimer log flush threshold: enough headroom for a busy request, small enough that
     * the C log cannot grow without bound before PHP aggregates it.
     */
    public const DEFAULT_MAX_SAMPLES = 10_000;

    public const MIN_MAX_SAMPLES = 1;

    public const MAX_MAX_SAMPLES = 1_000_000;

    /**
     * Distinct stacks kept in one profile window before further hits go to `[overflow]`.
     */
    public const DEFAULT_MAX_UNIQUE_STACKS = 4_096;

    public const MIN_MAX_UNIQUE_STACKS = 1;

    public const MAX_MAX_UNIQUE_STACKS = 100_000;

    public ?string $projectRoot;

    /**
     * @param int       $periodNs         time between two samples, in nanoseconds
     * @param EventType $eventType        whether the period is measured in wall-clock or CPU time
     * @param int       $maxDepth         maximum number of frames recorded per sample, counted from the
     *                                    innermost frame; callers beyond it are dropped
     * @param int       $maxSamples       Excimer flush-callback threshold; the C log is drained into
     *                                    the aggregator at least this often
     * @param int       $maxUniqueStacks  distinct stacks kept per profile window before overflow
     * @param ?string   $projectRoot      absolute app base path stripped from frame files and closure
     *                                    names so release directories do not split aggregation; null
     *                                    keeps paths as Excimer reports them
     */
    public function __construct(
        public int $periodNs = self::DEFAULT_PERIOD_NS,
        public EventType $eventType = EventType::Wall,
        public int $maxDepth = self::DEFAULT_MAX_DEPTH,
        public int $maxSamples = self::DEFAULT_MAX_SAMPLES,
        public int $maxUniqueStacks = self::DEFAULT_MAX_UNIQUE_STACKS,
        ?string $projectRoot = null,
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

        if ($maxSamples < self::MIN_MAX_SAMPLES || $maxSamples > self::MAX_MAX_SAMPLES) {
            throw new LogicException(\sprintf(
                'Maximum samples before a native flush must be between %d and %d, got %d.',
                self::MIN_MAX_SAMPLES,
                self::MAX_MAX_SAMPLES,
                $maxSamples,
            ));
        }

        if ($maxUniqueStacks < self::MIN_MAX_UNIQUE_STACKS || $maxUniqueStacks > self::MAX_MAX_UNIQUE_STACKS) {
            throw new LogicException(\sprintf(
                'Maximum unique stacks must be between %d and %d, got %d.',
                self::MIN_MAX_UNIQUE_STACKS,
                self::MAX_MAX_UNIQUE_STACKS,
                $maxUniqueStacks,
            ));
        }

        $this->projectRoot = $this->normalizeProjectRoot($projectRoot);
    }

    /**
     * Sampling period in seconds, the unit ExcimerProfiler expects.
     */
    public function periodSeconds(): float
    {
        return $this->periodNs / 1_000_000_000;
    }

    private function normalizeProjectRoot(?string $projectRoot): ?string
    {
        if (null === $projectRoot) {
            return null;
        }

        if ('' === trim($projectRoot)) {
            throw new LogicException('Project root must be a non-empty path when set.');
        }

        return rtrim($projectRoot, "/\\");
    }
}
