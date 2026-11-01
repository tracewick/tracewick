<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Sampler\EventType;

/**
 * Stable hash of the aggregation key from ADR 12, for spool file names and shard directories.
 *
 * Key: application, labels (sorted by key), event_type, period_ns.
 */
final readonly class SpoolAggregationKey
{
    /**
     * @param array<string, string> $labels
     */
    public static function hash(string $application, array $labels, EventType $eventType, int $periodNs): string
    {
        ksort($labels, \SORT_STRING);
        $parts = [$application, $eventType->value, (string) $periodNs];
        foreach ($labels as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return substr(hash('sha256', implode("\0", $parts)), 0, 16);
    }

    public static function shard(string $aggregationKeyHash): string
    {
        return substr($aggregationKeyHash, 0, 2);
    }
}
