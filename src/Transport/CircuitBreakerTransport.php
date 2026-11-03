<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Psr\Log\LogLevel;
use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;

/**
 * Stops sending to a destination that keeps failing, so that an outage costs a few timeouts
 * instead of one per profile.
 *
 * Transient {@see DeliveryStatus::Failed} deliveries open the circuit after
 * {@see CircuitBreakerConfig::$failureThreshold} consecutive failures. A
 * {@see DeliveryStatus::Configuration} error opens it at once for the longer configuration pause.
 * {@see DeliveryStatus::Rejected} leaves the circuit alone: the payload was bad, not the destination.
 * While open, payloads are skipped without touching the network. Once the open duration has
 * passed, the next payload is sent as a trial: success closes the circuit, a transient or
 * configuration failure opens it again. A process that takes the trial extends the open period
 * first, so that the other processes keep skipping while the trial runs.
 *
 * Protocol: {@see CircuitBreakerStore::acquire()} → HTTP → {@see CircuitBreakerStore::complete()}.
 * A stale complete (wrong generation) is ignored, so a late success cannot close a newer open.
 * Default store is in-process memory; set {@see CircuitBreakerConfig::$stateDirectory} for a
 * shared file store across workers.
 */
final readonly class CircuitBreakerTransport implements Transport
{
    public function __construct(
        private Transport $inner,
        private CircuitBreakerConfig $config = new CircuitBreakerConfig(),
        private Clock $clock = new SystemClock(),
        private CircuitBreakerStore $store = new InMemoryCircuitBreakerStore(),
        private DiagnosticLogger $logger = new DiagnosticLogger(),
    ) {}

    public function send(Payload $payload): Delivery
    {
        $nowNs = $this->clock->nowNs();
        $maxPauseMs = max($this->config->openDurationMs, $this->config->configurationOpenDurationMs);
        $attempt = $this->store->acquire($nowNs, $this->config->openDurationMs, $maxPauseMs);
        if (!$attempt instanceof CircuitBreakerAttempt) {
            return Delivery::skipped('Circuit breaker is open after repeated failures.');
        }

        $delivery = $this->inner->send($payload);

        return match ($delivery->status) {
            DeliveryStatus::Skipped, DeliveryStatus::Rejected, DeliveryStatus::Empty => $delivery,
            DeliveryStatus::Delivered => $this->onSuccess($delivery, $attempt),
            DeliveryStatus::Configuration => $this->onConfiguration($delivery, $attempt),
            DeliveryStatus::Failed => $this->onTransientFailure($delivery, $attempt),
        };
    }

    public function isOpen(): bool
    {
        $nowNs = $this->clock->nowNs();
        $maxPauseMs = max($this->config->openDurationMs, $this->config->configurationOpenDurationMs);
        $state = $this->store->load();
        $clamped = $this->clampOpenUntil($state, $nowNs, $maxPauseMs);
        if ($clamped->openUntilNs !== $state->openUntilNs) {
            $this->store->complete($state->generation, $clamped);
            $state = $this->store->load();
        }

        return null !== $state->openUntilNs && $nowNs < $state->openUntilNs;
    }

    private function onSuccess(Delivery $delivery, CircuitBreakerAttempt $attempt): Delivery
    {
        if (!$attempt->stateBefore->isClosed()) {
            $this->store->complete($attempt->generation, new CircuitBreakerState(generation: $attempt->generation));
        }

        if (null !== $attempt->stateBefore->openUntilNs) {
            $this->logger->log(LogLevel::INFO, 'Tracewick resumed sending profiles after a successful trial delivery.');
        }

        return $delivery;
    }

    private function onConfiguration(Delivery $delivery, CircuitBreakerAttempt $attempt): Delivery
    {
        $nowNs = $this->clock->nowNs();
        $this->store->complete($attempt->generation, new CircuitBreakerState(
            $this->config->failureThreshold,
            $this->openUntilNs($nowNs, $this->config->configurationOpenDurationMs),
            $attempt->generation,
        ));

        if (null === $attempt->stateBefore->openUntilNs || $attempt->stateBefore->openUntilNs <= $nowNs) {
            $this->logger->log(
                LogLevel::WARNING,
                'Tracewick stopped sending profiles for {pauseMs} ms after a configuration error: {reason}',
                ['pauseMs' => $this->config->configurationOpenDurationMs, 'reason' => $delivery->reason],
            );
        }

        return $delivery;
    }

    private function onTransientFailure(Delivery $delivery, CircuitBreakerAttempt $attempt): Delivery
    {
        $failures = min($attempt->stateBefore->consecutiveFailures + 1, $this->config->failureThreshold);
        $open = $attempt->takingTrial || $failures >= $this->config->failureThreshold;
        $this->store->complete($attempt->generation, new CircuitBreakerState(
            $failures,
            $open ? $this->openUntilNs($this->clock->nowNs(), $this->config->openDurationMs) : null,
            $attempt->generation,
        ));
        if ($open && !$attempt->takingTrial && null === $attempt->stateBefore->openUntilNs) {
            $this->logger->log(
                LogLevel::WARNING,
                'Tracewick stopped sending profiles for {pauseMs} ms after {failures} failed deliveries in a row: {reason}',
                ['pauseMs' => $this->config->openDurationMs, 'failures' => $failures, 'reason' => $delivery->reason],
            );
        }

        return $delivery;
    }

    private function openUntilNs(int $nowNs, int $durationMs): int
    {
        return $nowNs + $durationMs * 1_000_000;
    }

    private function clampOpenUntil(CircuitBreakerState $state, int $nowNs, int $maxPauseMs): CircuitBreakerState
    {
        if (null === $state->openUntilNs) {
            return $state;
        }

        $latestAllowed = $nowNs + $maxPauseMs * 1_000_000;
        if ($state->openUntilNs > $latestAllowed) {
            return new CircuitBreakerState($state->consecutiveFailures, $latestAllowed, $state->generation);
        }

        return $state;
    }
}
