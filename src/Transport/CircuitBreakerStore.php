<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Keeps the state of a {@see CircuitBreakerTransport}, in the process or shared between processes.
 *
 * Both methods run around every delivery, so they must be fast and must never throw: a store that
 * cannot read its state returns a closed circuit, and one that cannot write it forgets the change.
 * Writes from concurrent processes may overwrite each other; the circuit then opens a little
 * later or one more trial delivery goes out, which is harmless.
 */
interface CircuitBreakerStore
{
    public function load(): CircuitBreakerState;

    public function save(CircuitBreakerState $state): void;
}
