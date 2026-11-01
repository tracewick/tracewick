<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Keeps the state of a {@see CircuitBreakerTransport}, in the process or shared between processes.
 *
 * Both methods run around every delivery, so they must be fast and must never throw: a store that
 * cannot read its state returns a closed circuit, and one that cannot write it forgets the change.
 * {@see update()} serialises a load-modify-save so concurrent workers do not race the trial delivery.
 */
interface CircuitBreakerStore
{
    public function load(): CircuitBreakerState;

    public function save(CircuitBreakerState $state): void;

    /**
     * Loads the state, applies the updater, and saves the result under a lock when the store shares
     * state between processes.
     *
     * @param callable(CircuitBreakerState): CircuitBreakerState $updater
     */
    public function update(callable $updater): CircuitBreakerState;
}
