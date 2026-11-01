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
 * configuration failure opens it again. A process that takes the trial pushes the end of the open
 * period forward first, so that the other processes keep skipping while the trial runs.
 *
 * The state lives in a {@see CircuitBreakerStore}. The default keeps it in the process; a shared
 * file store coordinates PHP-FPM workers on one host.
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
        $state = $this->loadClamped($nowNs);

        if (null !== $state->openUntilNs && $nowNs < $state->openUntilNs) {
            return Delivery::skipped('Circuit breaker is open after repeated failures.');
        }

        $takingTrial = false;
        if (null !== $state->openUntilNs) {
            $takingTrial = true;
            $extendedUntil = $this->openUntilNs($nowNs, $this->config->openDurationMs);
            $state = $this->store->update(function (CircuitBreakerState $current) use ($nowNs, $extendedUntil): CircuitBreakerState {
                $current = $this->clampOpenUntil($current, $nowNs);
                if (null === $current->openUntilNs || $nowNs < $current->openUntilNs) {
                    return $current;
                }

                return new CircuitBreakerState($current->consecutiveFailures, $extendedUntil);
            });

            if (null === $state->openUntilNs) {
                $takingTrial = false;
            } elseif ($state->openUntilNs !== $extendedUntil) {
                return Delivery::skipped('Circuit breaker is open after repeated failures.');
            }
        }

        $delivery = $this->inner->send($payload);

        return match ($delivery->status) {
            DeliveryStatus::Skipped, DeliveryStatus::Rejected => $delivery,
            DeliveryStatus::Delivered => $this->onSuccess($delivery, $state),
            DeliveryStatus::Configuration => $this->onConfiguration($delivery, $state),
            DeliveryStatus::Failed => $this->onTransientFailure($delivery, $state, $takingTrial),
        };
    }

    public function isOpen(): bool
    {
        $nowNs = $this->clock->nowNs();
        $openUntilNs = $this->loadClamped($nowNs)->openUntilNs;

        return null !== $openUntilNs && $nowNs < $openUntilNs;
    }

    /**
     * Loads the store and persists a clamped open deadline when the file held a value further
     * ahead than both configured pauses allow.
     */
    private function loadClamped(int $nowNs): CircuitBreakerState
    {
        $state = $this->store->load();
        $clamped = $this->clampOpenUntil($state, $nowNs);
        if ($clamped->openUntilNs !== $state->openUntilNs) {
            $this->store->save($clamped);
        }

        return $clamped;
    }

    private function onSuccess(Delivery $delivery, CircuitBreakerState $stateBefore): Delivery
    {
        if (!$stateBefore->isClosed()) {
            $this->store->save(new CircuitBreakerState());
        }

        if (null !== $stateBefore->openUntilNs) {
            $this->logger->log(LogLevel::INFO, 'Tracewick resumed sending profiles after a successful trial delivery.');
        }

        return $delivery;
    }

    private function onConfiguration(Delivery $delivery, CircuitBreakerState $stateBefore): Delivery
    {
        $nowNs = $this->clock->nowNs();
        $this->store->save(new CircuitBreakerState(
            $this->config->failureThreshold,
            $this->openUntilNs($nowNs, $this->config->configurationOpenDurationMs),
        ));

        if (null === $stateBefore->openUntilNs || $stateBefore->openUntilNs <= $nowNs) {
            $this->logger->log(
                LogLevel::WARNING,
                'Tracewick stopped sending profiles for {pauseMs} ms after a configuration error: {reason}',
                ['pauseMs' => $this->config->configurationOpenDurationMs, 'reason' => $delivery->reason],
            );
        }

        return $delivery;
    }

    private function onTransientFailure(Delivery $delivery, CircuitBreakerState $stateBefore, bool $takingTrial): Delivery
    {
        $failures = min($stateBefore->consecutiveFailures + 1, $this->config->failureThreshold);
        $open = $takingTrial || $failures >= $this->config->failureThreshold;
        $this->store->save(new CircuitBreakerState(
            $failures,
            $open ? $this->openUntilNs($this->clock->nowNs(), $this->config->openDurationMs) : null,
        ));
        if ($open && !$takingTrial && null === $stateBefore->openUntilNs) {
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

    /**
     * Caps an open deadline that lies further ahead than both configured pauses allow, so a
     * corrupt or clock-skewed file cannot leave the circuit stuck open forever.
     */
    private function clampOpenUntil(CircuitBreakerState $state, int $nowNs): CircuitBreakerState
    {
        if (null === $state->openUntilNs) {
            return $state;
        }

        $maxPauseMs = max($this->config->openDurationMs, $this->config->configurationOpenDurationMs);
        $latestAllowed = $nowNs + $maxPauseMs * 1_000_000;
        if ($state->openUntilNs > $latestAllowed) {
            return new CircuitBreakerState($state->consecutiveFailures, $latestAllowed);
        }

        return $state;
    }
}
