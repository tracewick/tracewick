<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Permission to send one payload, with the generation {@see CircuitBreakerStore::complete()} must
 * match so a stale outcome cannot overwrite a newer open circuit.
 */
final readonly class CircuitBreakerAttempt
{
    public function __construct(
        public int $generation,
        public CircuitBreakerState $stateBefore,
        public bool $takingTrial,
    ) {}
}
