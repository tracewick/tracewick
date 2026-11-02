<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

/**
 * Stable counter names written by profilers.
 */
final class HostCounterKeys
{
    public const PROFILES_COLLECTED = 'profiles_collected';

    public const PROFILES_DROPPED = 'profiles_dropped';

    public const PROFILES_DELIVERED = 'profiles_delivered';

    public const PROFILES_REJECTED = 'profiles_rejected';

    public const PROFILES_FAILED = 'profiles_failed';

    public const PROFILES_SKIPPED = 'profiles_skipped';

    public const PROFILES_EMPTY = 'profiles_empty';

    public const PROFILES_SWALLOWED = 'profiles_swallowed_errors';

    private function __construct() {}
}
