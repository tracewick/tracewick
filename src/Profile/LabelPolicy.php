<?php

declare(strict_types=1);

namespace Tracewick\Profile;

/**
 * Rejects label keys and values that must not become Pyroscope series labels by default.
 *
 * This is a hard filter (not a warning): denied pairs are dropped before they reach a profile.
 * Integrations set safe names such as a route template; raw URL paths are opt-in elsewhere.
 */
final class LabelPolicy
{
    /**
     * Keys that typically carry high-cardinality or secret-bearing request data.
     *
     * @var list<string>
     */
    private const DENIED_KEYS = [
        'url',
        'uri',
        'path',
        'user',
        'user_id',
        'email',
        'span_id',
        'trace_id',
        'request_id',
    ];

    private const EMAIL = '/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/iD';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

    private const LONG_DIGITS = '/\d{16,}/D';

    /**
     * Whether the key is allowed as a series label.
     */
    public static function allowsKey(string $key): bool
    {
        return !\in_array(strtolower($key), self::DENIED_KEYS, true);
    }

    /**
     * Whether the value is safe enough to put on a series (no email, UUID, query mark, or long digit run).
     */
    public static function allowsValue(string $value): bool
    {
        if (str_contains($value, '?')) {
            return false;
        }

        if (1 === preg_match(self::EMAIL, $value)) {
            return false;
        }

        if (1 === preg_match(self::UUID, $value)) {
            return false;
        }

        return 1 !== preg_match(self::LONG_DIGITS, $value);
    }

    /**
     * Whether both key and value may be stored on a profile.
     */
    public static function admits(string $key, string $value): bool
    {
        return self::allowsKey($key) && self::allowsValue($value);
    }

    /**
     * Returns a copy with denied pairs removed. Order of remaining labels is preserved.
     */
    public static function filter(Labels $labels): Labels
    {
        $kept = [];
        foreach ($labels as $key => $value) {
            if (self::admits($key, $value)) {
                $kept[$key] = $value;
            }
        }

        return new Labels($kept);
    }
}
