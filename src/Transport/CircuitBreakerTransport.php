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
 * The state lives in a {@see CircuitBreakerStore}. The default keeps it in the process, which
 * suits long-running workers; a {@see FileCircuitBreakerStore} shares it between the short-lived
 * PHP-FPM requests of a host. A process that takes the trial delivery pushes the end of the open
 * period forward first, so that the other processes keep skipping while the trial runs.
 */
final readonly class CircuitBreakerTransport implements Transport
{
    public function __construct(
        private Transport $inner,
        private CircuitBreakerConfig $config = new CircuitBreakerConfig(),
        private Clock $clock = new SystemClock(),
        private CircuitBreakerStore $store = new InMemoryCircuitBreakerStore(),
    ) {}

    public function send(Payload $payload): Delivery
    {
        $state = $this->store->load();
        if (null !== $state->openUntilNs) {
            $nowNs = $this->clock->nowNs();
            if ($nowNs < $state->openUntilNs) {
                return Delivery::skipped('Circuit breaker is open after repeated failures.');
            }

            $this->store->save(new CircuitBreakerState($state->consecutiveFailures, $this->openUntilNs($nowNs)));
        }

        $delivery = $this->inner->send($payload);

        if (DeliveryStatus::Skipped === $delivery->status) {
            // The destination was not contacted, so this says nothing about its health.
            return $delivery;
        }

        if (DeliveryStatus::Failed !== $delivery->status) {
            if (!$state->isClosed()) {
                $this->store->save(new CircuitBreakerState());
            }

            return $delivery;
        }

        $failures = min($state->consecutiveFailures + 1, $this->config->failureThreshold);
        // A failed trial reopens the circuit at once; otherwise wait for the threshold.
        $open = null !== $state->openUntilNs || $failures >= $this->config->failureThreshold;
        $this->store->save(new CircuitBreakerState($failures, $open ? $this->openUntilNs($this->clock->nowNs()) : null));

        return $delivery;
    }

    public function isOpen(): bool
    {
        $openUntilNs = $this->store->load()->openUntilNs;

        return null !== $openUntilNs && $this->clock->nowNs() < $openUntilNs;
    }

    private function openUntilNs(int $nowNs): int
    {
        return $nowNs + $this->config->openDurationMs * 1_000_000;
    }
}
