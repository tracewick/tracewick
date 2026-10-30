<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * What a {@see CircuitBreakerTransport} remembers between deliveries.
 */
final readonly class CircuitBreakerState
{
    /**
     * @param int  $consecutiveFailures failed deliveries since the last successful one
     * @param ?int $openUntilNs         when the open circuit allows the next trial delivery, in
     *                                  Unix nanoseconds; null while the circuit is closed
     */
    public function __construct(
        public int $consecutiveFailures = 0,
        public ?int $openUntilNs = null,
    ) {}

    public function isClosed(): bool
    {
        return 0 === $this->consecutiveFailures && null === $this->openUntilNs;
    }
}
