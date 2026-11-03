<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * Process-local DNS cache for drain (and other long-lived senders).
 *
 * Successful lookups are kept for {@see self::DEFAULT_TTL_NS} so a drain process can refresh
 * between passes (k8s / Swarm VIP failover) without resolving mid-batch after files are claimed.
 * Failed lookups are not cached: {@see SystemDnsResolver} fails open to the hostname, and caching
 * that would force {@see stream_socket_client()} to resolve again unboundedly.
 *
 * Concurrent drain workers each keep their own cache.
 */
final class CachingDnsResolver implements DnsResolver
{
    /** Default cache lifetime (~60 s): long enough to skip per-POST lookups, short enough for VIP churn. */
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
        if ($this->isIpLiteral($address)) {
            $this->entries[$host] = ['address' => $address, 'expiresAtNs' => $nowNs + $this->ttlNs];
        } else {
            unset($this->entries[$host]);
        }

        return $address;
    }

    /**
     * Resolves and caches a usable IP for {@see $host}.
     *
     * Call this before claiming spool files. Returns false when resolution fails open to a
     * hostname so the caller can skip the pass instead of holding claims across a DNS hang.
     */
    public function prefetch(string $host): bool
    {
        return $this->isIpLiteral($this->resolve($host));
    }

    /**
     * Drops a cached address so the next {@see prefetch()} / {@see resolve()} looks up again.
     *
     * Use after a delivery failure that may mean the pinned VIP or pod IP is gone.
     */
    public function forget(string $host): void
    {
        unset($this->entries[$host]);
    }

    private function isIpLiteral(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return false !== @inet_pton(substr($host, 1, -1));
        }

        return false !== @inet_pton($host);
    }
}
