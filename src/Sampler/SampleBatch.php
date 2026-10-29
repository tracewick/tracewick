<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

/**
 * Samples collected by a sampler between two flushes.
 */
final readonly class SampleBatch
{
    /**
     * @param EventType   $eventType what the sampling period measures
     * @param int         $periodNs  sampling period in nanoseconds
     * @param int         $startNs   Unix time in nanoseconds at which the batch window opened
     * @param int         $endNs     Unix time in nanoseconds at which the batch window closed
     * @param list<Sample> $samples  samples in the order they were taken
     */
    public function __construct(
        public EventType $eventType,
        public int $periodNs,
        public int $startNs,
        public int $endNs,
        public array $samples = [],
    ) {}

    public function isEmpty(): bool
    {
        return [] === $this->samples;
    }

    /**
     * Total number of sampling periods in the batch, including overruns.
     */
    public function eventCount(): int
    {
        $total = 0;
        foreach ($this->samples as $sample) {
            $total += $sample->count;
        }

        return $total;
    }
}
