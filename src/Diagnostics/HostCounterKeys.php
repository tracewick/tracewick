<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

/**
 * Stable counter names written by profilers.
 *
 * Keys are product-prefixed so a shared {@see CounterStore} (APCu, a shared
 * directory) cannot collide with another library's counters.
 */
final class HostCounterKeys
{
    private const PREFIX = 'tracewick:';

    public const PROFILES_COLLECTED = self::PREFIX . 'profiles_collected';

    public const PROFILES_DROPPED = self::PREFIX . 'profiles_dropped';

    public const PROFILES_DELIVERED = self::PREFIX . 'profiles_delivered';

    public const PROFILES_REJECTED = self::PREFIX . 'profiles_rejected';

    public const PROFILES_FAILED = self::PREFIX . 'profiles_failed';

    public const PROFILES_SKIPPED = self::PREFIX . 'profiles_skipped';

    public const PROFILES_EMPTY = self::PREFIX . 'profiles_empty';

    public const PROFILES_SWALLOWED = self::PREFIX . 'profiles_swallowed_errors';
}
