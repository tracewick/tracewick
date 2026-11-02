<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use Tracewick\Transport\CircuitBreakerAttempt;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\CircuitBreakerStore;

/**
 * Circuit breaker store in memory that records every state written to it.
 */
final class RecordingCircuitBreakerStore implements CircuitBreakerStore
{
    /** @var list<CircuitBreakerState> */
    public array $saved = [];

    public function __construct(private CircuitBreakerState $state = new CircuitBreakerState()) {}

    public function load(): CircuitBreakerState
    {
        return $this->state;
    }

    public function acquire(int $nowNs, int $trialExtendMs, int $maxPauseMs): ?CircuitBreakerAttempt
    {
        $state = $this->clamp($this->state, $nowNs, $maxPauseMs);
        if ($state->openUntilNs !== $this->state->openUntilNs) {
            $this->state = $state;
            $this->saved[] = $state;
        }

        if (null !== $state->openUntilNs && $nowNs < $state->openUntilNs) {
            return null;
        }

        $takingTrial = null !== $state->openUntilNs;
        $generation = $state->generation + 1;
        $this->state = $takingTrial
            ? new CircuitBreakerState($state->consecutiveFailures, $nowNs + $trialExtendMs * 1_000_000, $generation)
            : new CircuitBreakerState($state->consecutiveFailures, null, $generation);
        if ($takingTrial) {
            $this->saved[] = $this->state;
        }

        return new CircuitBreakerAttempt($generation, $state, $takingTrial);
    }

    public function complete(int $generation, CircuitBreakerState $next): bool
    {
        if ($this->state->generation !== $generation) {
            return false;
        }

        $this->state = new CircuitBreakerState($next->consecutiveFailures, $next->openUntilNs, $generation);
        $this->saved[] = $this->state;

        return true;
    }

    private function clamp(CircuitBreakerState $state, int $nowNs, int $maxPauseMs): CircuitBreakerState
    {
        if (null === $state->openUntilNs) {
            return $state;
        }

        $latestAllowed = $nowNs + $maxPauseMs * 1_000_000;
        if ($state->openUntilNs <= $latestAllowed) {
            return $state;
        }

        return new CircuitBreakerState($state->consecutiveFailures, $latestAllowed, $state->generation);
    }
}
