<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Process-local cache of the spool file count so writers do not scandir on every flush.
 *
 * The count may briefly overshoot {@see SpoolConfig::$maxFiles} under concurrent writers; that is
 * accepted (soft admission). A full rescan runs at least every {@see self::REFRESH_EVERY_WRITES}
 * successful writes or when the cache is older than {@see self::TTL_NS}.
 */
final class SpoolAdmissionState
{
    public const TTL_NS = 1_000_000_000;

    public const REFRESH_EVERY_WRITES = 32;

    public int $cachedCount = 0;

    public int $cachedAtNs = 0;

    public int $writesSinceScan = 0;

    public function needsRefresh(int $nowNs): bool
    {
        return 0 === $this->cachedAtNs
            || $nowNs - $this->cachedAtNs >= self::TTL_NS
            || $this->writesSinceScan >= self::REFRESH_EVERY_WRITES;
    }

    public function refresh(int $count, int $nowNs): void
    {
        $this->cachedCount = $count;
        $this->cachedAtNs = $nowNs;
        $this->writesSinceScan = 0;
    }

    public function recordWrite(): void
    {
        ++$this->cachedCount;
        ++$this->writesSinceScan;
    }
}
