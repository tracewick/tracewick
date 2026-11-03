<?php

declare(strict_types=1);

namespace Tracewick\Clock;

/**
 * Wall clock anchored to system time and advanced by the monotonic clock.
 *
 * Timestamps never go backwards for small NTP adjustments. When wall time and the
 * monotonic projection diverge by more than one second (VM suspend, large NTP step),
 * the clock re-anchors so long-lived workers do not stamp profiles in the past.
 */
final class SystemClock implements Clock
{
    /**
     * Absolute drift between wall and projected time that triggers a re-anchor.
     */
    public const DRIFT_THRESHOLD_NS = 1_000_000_000;

    private int $anchorUnixNs;

    private int $anchorMonotonicNs;

    private int $reanchorCount = 0;

    /** @var \Closure(): int */
    private readonly \Closure $wallNs;

    /** @var \Closure(): int */
    private readonly \Closure $monotonicNs;

    /**
     * @param ?\Closure(): int $wallNs       Unix time in nanoseconds; defaults to microtime
     * @param ?\Closure(): int $monotonicNs  monotonic nanoseconds; defaults to hrtime
     *
     * @internal the callables exist so tests can force drift without sleeping
     */
    public function __construct(?\Closure $wallNs = null, ?\Closure $monotonicNs = null)
    {
        $this->wallNs = $wallNs ?? static fn(): int => (int) (microtime(true) * 1_000_000) * 1_000;
        $this->monotonicNs = $monotonicNs ?? static fn(): int => (int) hrtime(true);
        $this->anchorMonotonicNs = ($this->monotonicNs)();
        $this->anchorUnixNs = ($this->wallNs)();
    }

    public function nowNs(): int
    {
        $monotonic = ($this->monotonicNs)();
        $projected = $this->anchorUnixNs + ($monotonic - $this->anchorMonotonicNs);
        $wall = ($this->wallNs)();
        $drift = $projected >= $wall ? $projected - $wall : $wall - $projected;
        if ($drift > self::DRIFT_THRESHOLD_NS) {
            $this->anchorUnixNs = $wall;
            $this->anchorMonotonicNs = $monotonic;
            ++$this->reanchorCount;
            error_log('Tracewick SystemClock: re-anchored after wall/monotonic drift exceeded 1s.');

            return $wall;
        }

        return $projected;
    }

    /**
     * How many times this instance re-anchored since construction.
     */
    public function reanchorCount(): int
    {
        return $this->reanchorCount;
    }
}
