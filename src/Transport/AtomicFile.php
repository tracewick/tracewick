<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Replaces a small file in one step, so that a concurrent reader sees either the old contents or
 * the new ones, never a part.
 *
 * The temporary path is created with {@see fopen()} mode `xb` (O_EXCL): an existing symlink or
 * file at that name is refused, not followed. The name uses {@see random_bytes()}, not the
 * process id.
 *
 * @internal
 */
final class AtomicFile
{
    /**
     * Writes the contents next to the file and renames them over it.
     *
     * @return bool true when the target path holds exactly the given contents; false when the write
     *              could not complete — the previous target (if any) is left unchanged
     */
    public static function write(string $path, string $contents): bool
    {
        $temporary = \sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(8)));
        $handle = @fopen($temporary, 'xb');
        if (false === $handle) {
            return false;
        }

        $complete = false;
        try {
            $expected = \strlen($contents);
            $written = @fwrite($handle, $contents);
            $complete = $expected === $written && @fflush($handle);
        } finally {
            fclose($handle);
        }

        if (!$complete || !@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }
}
