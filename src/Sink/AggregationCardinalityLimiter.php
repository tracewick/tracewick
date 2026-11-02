<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Diagnostics\CounterStore;
use Tracewick\Diagnostics\NullCounterStore;
use Tracewick\Exception\LogicException;
use Tracewick\Sampler\EventType;

/**
 * Caps unique ADR-12 aggregation keys on a host; further combinations collapse to {@see COLLAPSED_VALUE}.
 */
final readonly class AggregationCardinalityLimiter
{
    public const DEFAULT_MAX_UNIQUE_KEYS = 256;

    public const COLLAPSED_LABEL = 'cardinality';

    public const COLLAPSED_VALUE = 'other';

    /** Product-prefixed so a shared {@see CounterStore} cannot collide with other libraries. */
    private const META_UNIQUE = 'tracewick:agg:unique_count';

    private const SEEN_PREFIX = 'tracewick:agg:seen:';

    /**
     * @throws LogicException when $maxUniqueKeys is less than 1
     */
    public function __construct(
        private CounterStore $store = new NullCounterStore(),
        private int $maxUniqueKeys = self::DEFAULT_MAX_UNIQUE_KEYS,
    ) {
        if ($maxUniqueKeys < 1) {
            throw new LogicException(\sprintf('The maximum number of unique aggregation keys must be at least 1, got %d.', $maxUniqueKeys));
        }
    }

    /**
     * @param array<string, string> $labels
     *
     * @return array<string, string>
     */
    public function limitLabels(string $application, array $labels, EventType $eventType, int $periodNs): array
    {
        $hash = SpoolAggregationKey::hash($application, $labels, $eventType, $periodNs);
        $seenKey = self::SEEN_PREFIX . $hash;
        if ($this->store->get($seenKey) > 0) {
            $this->store->increment($seenKey);

            return $labels;
        }

        if ($this->store->get(self::META_UNIQUE) >= $this->maxUniqueKeys) {
            return [self::COLLAPSED_LABEL => self::COLLAPSED_VALUE];
        }

        $this->store->increment(self::META_UNIQUE);
        $this->store->increment($seenKey);

        return $labels;
    }
}
