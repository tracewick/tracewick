<?php

declare(strict_types=1);

namespace Tracewick\Clock;

/**
 * Source of wall-clock time for timestamps that end up in profiles.
 */
interface Clock
{
    /**
     * Returns the current Unix time in nanoseconds.
     */
    public function nowNs(): int;
}
