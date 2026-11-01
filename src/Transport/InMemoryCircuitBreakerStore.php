<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Keeps the circuit state in the process, for long-running workers and tests.
 */
final class InMemoryCircuitBreakerStore implements CircuitBreakerStore
{
    private CircuitBreakerState $state;

    public function __construct()
    {
        $this->state = new CircuitBreakerState();
    }

    public function load(): CircuitBreakerState
    {
        return $this->state;
    }

    public function save(CircuitBreakerState $state): void
    {
        $this->state = $state;
    }

    public function update(callable $updater): CircuitBreakerState
    {
        $this->state = $updater($this->state);

        return $this->state;
    }
}
