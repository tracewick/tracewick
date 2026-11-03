<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Psr\Log\LogLevel;
use Tracewick\Diagnostics\DiagnosticLogger;

/**
 * Prefers a shared file store, and falls back to an in-memory store for this process when the
 * file cannot be used. The switch happens at most once per process and is logged.
 */
final class FallbackCircuitBreakerStore implements CircuitBreakerStore
{
    private CircuitBreakerStore $active;

    private bool $fellBack = false;

    public function __construct(
        private readonly FileCircuitBreakerStore $file,
        private readonly DiagnosticLogger $logger = new DiagnosticLogger(),
    ) {
        $this->active = $file;
    }

    /**
     * @param string $destination anything that identifies the destination, such as its URL
     */
    public static function forDestination(string $directory, string $destination): self
    {
        return new self(FileCircuitBreakerStore::forDestination($directory, $destination));
    }

    public function load(): CircuitBreakerState
    {
        return $this->active->load();
    }

    public function acquire(int $nowNs, int $trialExtendMs, int $maxPauseMs): ?CircuitBreakerAttempt
    {
        if ($this->fellBack) {
            return $this->active->acquire($nowNs, $trialExtendMs, $maxPauseMs);
        }

        $attempt = $this->file->acquire($nowNs, $trialExtendMs, $maxPauseMs);
        if ($attempt instanceof CircuitBreakerAttempt) {
            return $attempt;
        }

        $state = $this->file->load();
        if (null !== $state->openUntilNs && $nowNs < $state->openUntilNs) {
            return null;
        }

        // Lock or write failed while the circuit looks closed — degrade to memory.
        $this->fallBack($state);

        return $this->active->acquire($nowNs, $trialExtendMs, $maxPauseMs);
    }

    public function complete(int $generation, CircuitBreakerState $next): bool
    {
        if ($this->fellBack) {
            return $this->active->complete($generation, $next);
        }

        return $this->file->complete($generation, $next);
    }

    private function fallBack(CircuitBreakerState $state): void
    {
        $this->fellBack = true;
        $this->active = new InMemoryCircuitBreakerStore($state);
        $this->logger->log(
            LogLevel::WARNING,
            'Tracewick could not persist the circuit breaker state to disk; using in-memory state for this process.',
        );
    }
}
