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
     * an address or resolution fails open to the OS resolver at connect time.
     */
    public function resolve(string $host): string;
}
