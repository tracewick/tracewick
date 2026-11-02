<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Keeps the state of a {@see CircuitBreakerTransport}, in the process or shared between processes.
 *
 * Callers take an {@see acquire()} permission, send HTTP outside any lock, then
 * {@see complete()} the outcome. A complete with a stale generation is ignored, so a successful
 * trial cannot close a circuit that another process has already re-opened. Methods must be fast
 * and must never throw: a store that cannot read returns a closed circuit; one that cannot write
 * forgets the change.
 */
interface CircuitBreakerStore
{
    public function load(): CircuitBreakerState;

    /**
     * Decides whether a send may proceed. When the circuit is open, returns null. When a trial is
     * taken, extends {@see CircuitBreakerState::$openUntilNs} under the store lock so other
     * processes keep skipping for the trial duration.
     *
     * @param int $nowNs           wall clock for open / trial decisions
     * @param int $trialExtendMs   how long to keep others skipping during a trial
     * @param int $maxPauseMs      longest configured pause; used to clamp a corrupt open deadline
     */
    public function acquire(int $nowNs, int $trialExtendMs, int $maxPauseMs): ?CircuitBreakerAttempt;

    /**
     * Applies the outcome of an attempt. Returns false when the generation no longer matches
     * (another acquire happened), so the caller must not treat the write as applied.
     */
    public function complete(int $generation, CircuitBreakerState $next): bool;
}
