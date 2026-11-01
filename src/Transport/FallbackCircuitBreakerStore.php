<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Psr\Log\LogLevel;
use Tracewick\Diagnostics\DiagnosticLogger;

/**
 * Prefers a shared file store, and falls back to an in-memory store for this process when the
 * file cannot be written. The switch happens at most once per process and is logged.
 */
final class FallbackCircuitBreakerStore implements CircuitBreakerStore
{
    private CircuitBreakerStore $active;

    private bool $fellBack = false;

    public function __construct(
        private readonly FileCircuitBreakerStore $file,
        private readonly InMemoryCircuitBreakerStore $memory = new InMemoryCircuitBreakerStore(),
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

    public function save(CircuitBreakerState $state): void
    {
        if ($this->fellBack) {
            $this->active->save($state);

            return;
        }

        $this->file->save($state);
        if ($this->file->persisted($state)) {
            return;
        }

        $this->fallBack($state);
    }

    public function update(callable $updater): CircuitBreakerState
    {
        if ($this->fellBack) {
            return $this->active->update($updater);
        }

        $state = $this->file->update($updater);
        if ($this->file->persisted($state)) {
            return $state;
        }

        $this->fallBack($state);

        return $state;
    }

    private function fallBack(CircuitBreakerState $state): void
    {
        $this->fellBack = true;
        $this->memory->save($state);
        $this->active = $this->memory;
        $this->logger->log(
            LogLevel::WARNING,
            'Tracewick could not persist the circuit breaker state to disk; using in-memory state for this process.',
        );
    }
}
