<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Profile\Profile;
use Tracewick\Profile\ProfileMerger;
use Tracewick\Sink\SpoolAggregationKey;

/**
 * In-process merge of profiles that share an ADR 12 aggregation key and time window.
 *
 * Used by {@see ContinuousProfiler} so long-running workers send one payload per key per window
 * instead of one per Excimer segment. Drain uses the same key and window on spool files.
 */
final class ProfileAccumulator
{
    public const DEFAULT_MAX_OPEN_BUCKETS = 64;

    /** @var array<string, Profile> */
    private array $buckets = [];

    private int $droppedProfiles = 0;

    private readonly ProfileMerger $merger;

    public function __construct(
        private readonly string $application,
        private readonly SpoolAggregationWindow $window = new SpoolAggregationWindow(),
        private readonly int $maxOpenBuckets = self::DEFAULT_MAX_OPEN_BUCKETS,
        ?ProfileMerger $merger = null,
    ) {
        $this->merger = $merger ?? new ProfileMerger();
    }

    /**
     * Merges {@see $profile} into its open window bucket, or drops it when the bucket cap is full.
     */
    public function add(Profile $profile): void
    {
        if ($profile->isEmpty()) {
            return;
        }

        $key = $this->bucketKey($profile);
        if (isset($this->buckets[$key])) {
            $this->buckets[$key] = $this->merger->merge([$this->buckets[$key], $profile]);

            return;
        }

        if (\count($this->buckets) >= $this->maxOpenBuckets) {
            ++$this->droppedProfiles;

            return;
        }

        $this->buckets[$key] = $profile;
    }

    /**
     * Profiles whose aggregation windows are fully in the past relative to {@see $nowNs}.
     *
     * @return list<Profile>
     */
    public function drainClosed(int $nowNs): array
    {
        $closed = [];
        foreach ($this->buckets as $key => $profile) {
            if ($this->window->isClosed($this->window->idFor($profile->startNs), $nowNs)) {
                $closed[] = $profile;
                unset($this->buckets[$key]);
            }
        }

        return $closed;
    }

    /**
     * Removes every open bucket, for a final flush when profiling stops.
     *
     * @return list<Profile>
     */
    public function drainAll(): array
    {
        $profiles = array_values($this->buckets);
        $this->buckets = [];

        return $profiles;
    }

    public function openBuckets(): int
    {
        return \count($this->buckets);
    }

    public function droppedProfiles(): int
    {
        return $this->droppedProfiles;
    }

    private function bucketKey(Profile $profile): string
    {
        return SpoolAggregationKey::hash(
            $this->application,
            $profile->labels->toArray(),
            $profile->eventType,
            $profile->periodNs,
        ) . "\0" . $this->window->idFor($profile->startNs);
    }
}
