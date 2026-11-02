<?php

declare(strict_types=1);

namespace Tracewick\Profile;

/**
 * Cheap heuristics for label values that often carry personal data by mistake.
 *
 * Warn-only: callers decide whether to log. Values are never stripped or rejected here.
 */
final class LabelPiiHeuristics
{
    private const EMAIL = '/^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/iD';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

    private const LONG_DIGITS = '/\d{16,}/D';

    /**
     * @return list<string> reasons, empty when the value does not look like PII
     */
    public static function reasons(string $value): array
    {
        $reasons = [];
        if (1 === preg_match(self::EMAIL, $value)) {
            $reasons[] = 'email';
        }

        if (1 === preg_match(self::UUID, $value)) {
            $reasons[] = 'uuid';
        }

        if (1 === preg_match(self::LONG_DIGITS, $value)) {
            $reasons[] = 'long_digits';
        }

        return $reasons;
    }

    /**
     * @return list<string> label keys whose values look like PII
     */
    public static function suspiciousKeys(Labels $labels): array
    {
        $keys = [];
        foreach ($labels as $key => $value) {
            if ([] !== self::reasons($value)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
