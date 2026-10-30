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

    /**
     * @param int     $failureThreshold consecutive failed deliveries that open the circuit
     * @param int     $openDurationMs   how long the circuit stays open before one trial delivery
     * @param ?string $stateDirectory   where sinks keep the circuit state that the processes of a
     *                                  host share; the system temporary directory by default
     *
     * @throws LogicException when a value is out of range
     */
    public function __construct(
        public int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        public int $openDurationMs = self::DEFAULT_OPEN_DURATION_MS,
        public ?string $stateDirectory = null,
    ) {
        if ($failureThreshold < 1 || $failureThreshold > 100) {
            throw new LogicException(\sprintf('The failure threshold must be between 1 and 100, got %d.', $failureThreshold));
        }

        if ($openDurationMs < 1 || $openDurationMs > 3_600_000) {
            throw new LogicException(\sprintf('The open duration must be between 1 ms and 1 hour, got %d ms.', $openDurationMs));
        }
    }

    /**
     * A store shared by the processes of this host for the given destination.
     *
     * @param string $destination anything that identifies the destination, such as its URL
     */
    public function sharedStore(string $destination): FileCircuitBreakerStore
    {
        return FileCircuitBreakerStore::forDestination($this->stateDirectory ?? sys_get_temp_dir(), $destination);
    }
}
