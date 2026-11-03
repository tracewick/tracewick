<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Compares encoder output with a checked-in golden file.
 *
 * Run the tests with `TRACEWICK_UPDATE_GOLDEN=1` to rewrite the golden files after an intended
 * format change, then review the diff before committing it.
 */
final class GoldenFile
{
    public const UPDATE_ENV = 'TRACEWICK_UPDATE_GOLDEN';

    public static function assertMatches(string $path, string $actual): void
    {
        if ('1' === getenv(self::UPDATE_ENV)) {
            file_put_contents($path, $actual);
        }

        Assert::assertFileExists($path, \sprintf('Golden file is missing; run the tests with %s=1 to create it.', self::UPDATE_ENV));
        Assert::assertSame(file_get_contents($path), $actual, \sprintf('Output differs from %s.', basename($path)));
    }
}
