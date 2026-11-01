<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;
use Tracewick\Sink\ApplicationName;

/**
 * Settings of a {@see ContinuousProfiler}.
 */
final readonly class ContinuousProfilerConfig
{
    /**
     * @param string                 $applicationName  service name used in the aggregation key (ADR 12)
     * @param Labels                 $labels           process-level labels on every segment; not per request
     * @param SpoolAggregationWindow $aggregationWindow same 10–15 s window as drain
     * @param int                    $maxOpenBuckets   open key×window buckets kept before new segments drop
     *
     * @throws LogicException when a value is invalid
     */
    public function __construct(
        public string $applicationName,
        public Labels $labels = new Labels(),
        public SpoolAggregationWindow $aggregationWindow = new SpoolAggregationWindow(),
        public int $maxOpenBuckets = ProfileAccumulator::DEFAULT_MAX_OPEN_BUCKETS,
    ) {
        ApplicationName::assertValid($applicationName);

        if ($maxOpenBuckets < 1 || $maxOpenBuckets > ProfilerConfig::MAX_MAX_BUFFERED_PROFILES) {
            throw new LogicException(\sprintf(
                'The number of open aggregation buckets must be between 1 and %d, got %d.',
                ProfilerConfig::MAX_MAX_BUFFERED_PROFILES,
                $maxOpenBuckets,
            ));
        }
    }
}
