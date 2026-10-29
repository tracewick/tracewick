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
     * @param int $failureThreshold consecutive failed deliveries that open the circuit
     * @param int $openDurationMs   how long the circuit stays open before one trial delivery
     *
     * @throws LogicException when a value is out of range
     */
    public function __construct(
        public int $failureThreshold = self::DEFAULT_FAILURE_THRESHOLD,
        public int $openDurationMs = self::DEFAULT_OPEN_DURATION_MS,
    ) {
        if ($failureThreshold < 1 || $failureThreshold > 100) {
            throw new LogicException(\sprintf('The failure threshold must be between 1 and 100, got %d.', $failureThreshold));
        }

        if ($openDurationMs < 1 || $openDurationMs > 3_600_000) {
            throw new LogicException(\sprintf('The open duration must be between 1 ms and 1 hour, got %d ms.', $openDurationMs));
        }
    }
}
