<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Exception\LogicException;

/**
 * Wall-clock window used to merge spool files that share an aggregation key
 * ([ADR 12](../../docs/adr/0012-aggregate-before-delivery.md)).
 */
final readonly class SpoolAggregationWindow
{
    public const DEFAULT_SECONDS = 15;

    public const MIN_SECONDS = 10;

    public const MAX_SECONDS = 15;

    public int $durationNs;

    /**
     * @throws LogicException when the duration is outside 10–15 seconds
     */
    public function __construct(int $seconds = self::DEFAULT_SECONDS)
    {
        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new LogicException(\sprintf(
                'The aggregation window must be between %d and %d seconds, got %d.',
                self::MIN_SECONDS,
                self::MAX_SECONDS,
                $seconds,
            ));
        }

        $this->durationNs = $seconds * 1_000_000_000;
    }

    /**
     * Bucket id for a profile start time: files with the same id belong to one merge group.
     */
    public function idFor(int $startNs): int
    {
        return intdiv($startNs, $this->durationNs);
    }

    /**
     * Whether the bucket is fully in the past relative to {@see $nowNs}, so drain may send it.
     */
    public function isClosed(int $windowId, int $nowNs): bool
    {
        return $windowId < $this->idFor($nowNs);
    }
}
