<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * Process-local DNS cache for drain (and other long-lived senders).
 *
 * Entries live for {@see self::DEFAULT_TTL_NS}. Concurrent drain workers each keep their own
 * cache; that is enough to avoid a lookup on every POST inside one process.
 */
final class CachingDnsResolver implements DnsResolver
{
    public const DEFAULT_TTL_NS = 60_000_000_000;

    /** @var array<string, array{address: string, expiresAtNs: int}> */
    private array $entries = [];

    public function __construct(
        private readonly DnsResolver $inner = new SystemDnsResolver(),
        private readonly Clock $clock = new SystemClock(),
        private readonly int $ttlNs = self::DEFAULT_TTL_NS,
    ) {}

    public function resolve(string $host): string
    {
        $nowNs = $this->clock->nowNs();
        $cached = $this->entries[$host] ?? null;
        if (null !== $cached && $cached['expiresAtNs'] > $nowNs) {
            return $cached['address'];
        }

        $address = $this->inner->resolve($host);
        $this->entries[$host] = ['address' => $address, 'expiresAtNs' => $nowNs + $this->ttlNs];

        return $address;
    }
}
