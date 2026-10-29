<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Exception\LogicException;

/**
 * Validates application names, which sinks pass to backends as the service name.
 *
 * @internal
 */
final class ApplicationName
{
    /**
     * Letters, digits, dots, underscores and dashes: names Pyroscope accepts as a service name
     * without escaping.
     */
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,255}$/D';

    /**
     * @throws LogicException when the name is invalid
     */
    public static function assertValid(string $name): void
    {
        if (1 !== preg_match(self::PATTERN, $name)) {
            throw new LogicException(\sprintf('The application name must be 1 to 256 letters, digits, dots, underscores or dashes, starting with a letter or digit, got "%s".', $name));
        }
    }
}
