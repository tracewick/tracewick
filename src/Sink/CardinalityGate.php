<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Diagnostics\CounterStore;
use Tracewick\Diagnostics\NullCounterStore;
use Tracewick\Exception\LogicException;
use Tracewick\Sampler\EventType;

/**
 * Caps distinct ADR-12 aggregation keys in one scope; further combinations keep base labels and
 * collapse the rest to {@see COLLAPSED_VALUE}.
 *
 * Alpha wiring: {@see \Tracewick\Runtime\SpoolDrainer} uses a process-local
 * {@see \Tracewick\Diagnostics\ArrayCounterStore} by default. {@see SpoolSink} and
 * {@see \Tracewick\Runtime\ContinuousProfiler} default to {@see NullCounterStore}, which never
 * collapses (opt-in a real store to enable). Host-wide APCu is not the alpha default.
 *
 * Separate from {@see \Tracewick\Profile\LabelPolicy} (deny/normalize before spool).
 */
final readonly class CardinalityGate
{
    public const DEFAULT_MAX_UNIQUE_KEYS = 256;

    public const COLLAPSED_LABEL = 'cardinality';

    public const COLLAPSED_VALUE = 'other';

    /**
     * Labels preserved on overflow (dynamic keys are dropped). Application name stays on the
     * spool/Pyroscope series separately from this map.
     *
     * @var list<string>
     */
    public const BASE_LABEL_KEYS = ['service', 'env'];

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
            return $this->overflowLabels($labels);
        }

        $this->store->increment(self::META_UNIQUE);
        $this->store->increment($seenKey);

        return $labels;
    }

    /**
     * @param array<string, string> $labels
     *
     * @return array<string, string>
     */
    private function overflowLabels(array $labels): array
    {
        $kept = [];
        foreach (self::BASE_LABEL_KEYS as $key) {
            if (isset($labels[$key])) {
                $kept[$key] = $labels[$key];
            }
        }
        $kept[self::COLLAPSED_LABEL] = self::COLLAPSED_VALUE;

        return $kept;
    }
}
