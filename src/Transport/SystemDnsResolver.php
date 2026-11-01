<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Resolves host names with {@see gethostbynamel()}, preferring the first IPv4 address.
 */
final readonly class SystemDnsResolver implements DnsResolver
{
    public function resolve(string $host): string
    {
        if ($this->isIpLiteral($host)) {
            return $host;
        }

        $addresses = @gethostbynamel($host);
        if (false === $addresses || [] === $addresses) {
            return $host;
        }

        return $addresses[0];
    }

    private function isIpLiteral(string $host): bool
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return false !== @inet_pton(substr($host, 1, -1));
        }

        return false !== @inet_pton($host);
    }
}
