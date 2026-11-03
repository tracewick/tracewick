<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Exception\LogicException;

/**
 * When a {@see CircuitBreakerTransport} stops sending, and for how long.
 */
final readonly class CircuitBreakerConfig
{
    public const DEFAULT_FAILURE_THRESHOLD = 3;

    public const DEFAULT_OPEN_DURATION_MS = 30_000;

    public const DEFAULT_CONFIGURATION_OPEN_DURATION_MS = 300_000;

    /**
     * @param int     $failureThreshold             consecutive transient failures that open the circuit
     * @param int     $openDurationMs               how long the circuit stays open after transient failures
     *                                              before one trial delivery
     * @param int     $configurationOpenDurationMs  how long the circuit stays open after a configuration error
     * @param ?string $stateDirectory               optional shared-file directory for multi-process delivery
     *                                              (drain sidecars that share a host). Null = in-memory only
     *                                              (default; correct for FPM→spool and single drain)
     *
     * @throws LogicException when a value is out of range
     */
    public function __construct(
        public int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        public int $openDurationMs = self::DEFAULT_OPEN_DURATION_MS,
        public ?string $stateDirectory = null,
        public int $configurationOpenDurationMs = self::DEFAULT_CONFIGURATION_OPEN_DURATION_MS,
    ) {
        if ($failureThreshold < 1 || $failureThreshold > 100) {
            throw new LogicException(\sprintf('The failure threshold must be between 1 and 100, got %d.', $failureThreshold));
        }

        if ($openDurationMs < 1 || $openDurationMs > 3_600_000) {
            throw new LogicException(\sprintf('The open duration must be between 1 ms and 1 hour, got %d ms.', $openDurationMs));
        }

        if ($configurationOpenDurationMs < 1 || $configurationOpenDurationMs > 3_600_000) {
            throw new LogicException(\sprintf(
                'The configuration open duration must be between 1 ms and 1 hour, got %d ms.',
                $configurationOpenDurationMs,
            ));
        }
    }

    /**
     * Store for this destination: in-memory unless {@see $stateDirectory} opts into a shared file.
     *
     * @param string $destination anything that identifies the destination, such as its URL
     */
    public function storeFor(string $destination): CircuitBreakerStore
    {
        if (null === $this->stateDirectory) {
            return new InMemoryCircuitBreakerStore();
        }

        return FallbackCircuitBreakerStore::forDestination($this->stateDirectory, $destination);
    }
}
