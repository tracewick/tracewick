<?php

declare(strict_types=1);

namespace Tracewick\Sink;

/**
 * Parses Tracewick spool file basenames written by {@see \Tracewick\Transport\SpoolTransport}.
 *
 * With an aggregation-key hash: `<time>-<aggHash>-<random>.spool`.
 * Without: `<time>-<random>.spool`.
 */
final readonly class SpoolFileName
{
    private const WITH_HASH = '/^\d{20}-([0-9a-f]{16})-[0-9a-f]{16}\.spool$/D';

    /**
     * Aggregation-key hash from the basename, or null when the name has no hash segment.
     */
    public static function aggregationKeyHash(string $name): ?string
    {
        $base = basename($name);
        if (1 !== preg_match(self::WITH_HASH, $base, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
