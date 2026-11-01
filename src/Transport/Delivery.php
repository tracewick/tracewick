<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Result of a delivery attempt, with a short human-readable reason when it did not succeed.
 */
final readonly class Delivery
{
    private function __construct(
        public DeliveryStatus $status,
        public string $reason,
    ) {}

    public static function delivered(): self
    {
        return new self(DeliveryStatus::Delivered, '');
    }

    public static function rejected(string $reason): self
    {
        return new self(DeliveryStatus::Rejected, $reason);
    }

    public static function configuration(string $reason): self
    {
        return new self(DeliveryStatus::Configuration, $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(DeliveryStatus::Failed, $reason);
    }

    public static function skipped(string $reason): self
    {
        return new self(DeliveryStatus::Skipped, $reason);
    }

    public function isDelivered(): bool
    {
        return DeliveryStatus::Delivered === $this->status;
    }
}
