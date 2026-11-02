<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Resolves a host name to an address suitable for {@see stream_socket_client()}.
 */
interface DnsResolver
{
    /**
     * Returns an IP literal (IPv6 in brackets when needed) or the original host when it is already
     * an address. When lookup fails the implementation may return the original hostname so the OS
     * resolver runs at connect time — callers that must not hang mid-batch should treat a non-IP
     * result as failure (see {@see CachingDnsResolver::prefetch()}).
     */
    public function resolve(string $host): string;
}
