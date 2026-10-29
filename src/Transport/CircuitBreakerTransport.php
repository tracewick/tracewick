<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * Stops sending to a destination that keeps failing, so that an outage costs a few timeouts
 * instead of one per profile.
 *
 * After {@see CircuitBreakerConfig::$failureThreshold} consecutive failed deliveries the circuit
 * opens and payloads are skipped without touching the network. Once the open duration has
 * passed, the next payload is sent as a trial: success closes the circuit, failure opens it
 * again. A rejected payload proves the destination is reachable, so it counts as a success; a
 * payload the inner transport skipped does not count either way.
 *
 * The state lives in the process. Long-running workers keep it between profiles; short-lived
 * PHP-FPM requests start closed each time.
 */
final class CircuitBreakerTransport implements Transport
{
    private int $consecutiveFailures = 0;

    private ?int $openUntilNs = null;

    public function __construct(
        private readonly Transport $inner,
        private readonly CircuitBreakerConfig $config = new CircuitBreakerConfig(),
        private readonly Clock $clock = new SystemClock(),
    ) {}

    public function send(Payload $payload): Delivery
    {
        if (null !== $this->openUntilNs && $this->clock->nowNs() < $this->openUntilNs) {
            return Delivery::skipped('Circuit breaker is open after repeated failures.');
        }

        $delivery = $this->inner->send($payload);

        if (DeliveryStatus::Skipped === $delivery->status) {
            // The destination was not contacted, so this says nothing about its health.
            return $delivery;
        }

        if (DeliveryStatus::Failed !== $delivery->status) {
            $this->consecutiveFailures = 0;
            $this->openUntilNs = null;

            return $delivery;
        }

        ++$this->consecutiveFailures;
        // A failed trial reopens the circuit at once; otherwise wait for the threshold.
        if (null !== $this->openUntilNs || $this->consecutiveFailures >= $this->config->failureThreshold) {
            $this->openUntilNs = $this->clock->nowNs() + $this->config->openDurationMs * 1_000_000;
        }

        return $delivery;
    }

    public function isOpen(): bool
    {
        return null !== $this->openUntilNs && $this->clock->nowNs() < $this->openUntilNs;
    }
}
