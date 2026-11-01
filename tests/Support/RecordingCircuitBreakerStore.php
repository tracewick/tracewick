<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

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

    public function save(CircuitBreakerState $state): void
    {
        $this->saved[] = $state;
        $this->state = $state;
    }

    public function update(callable $updater): CircuitBreakerState
    {
        $state = $updater($this->state);
        $this->saved[] = $state;
        $this->state = $state;

        return $state;
    }
}
