<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Replaces a small file in one step, so that a concurrent reader sees either the old contents or
 * the new ones, never a part.
 *
 * @internal
 */
final class AtomicFile
{
    /**
     * Writes the contents next to the file and renames them over it. Errors are swallowed: the
     * file is then left as it was.
     */
    public static function write(string $path, string $contents): void
    {
        $temporary = \sprintf('%s.%d.tmp', $path, getmypid());
        if (false === @file_put_contents($temporary, $contents) || !@rename($temporary, $path)) {
            @unlink($temporary);
        }
    }
}
