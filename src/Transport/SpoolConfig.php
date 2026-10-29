<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Exception\LogicException;

/**
 * Where a {@see SpoolTransport} writes files and how much it may write.
 *
 * The directory never holds more than `maxFiles × maxFileBytes` bytes of spool files, and a write
 * never leaves less than {@see $minFreeBytes} free on the file system.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final readonly class SpoolConfig
{
    public const DEFAULT_MAX_FILES = 1_000;

    public const DEFAULT_MAX_FILE_BYTES = 4 * 1024 * 1024;

    public const DEFAULT_MIN_FREE_BYTES = 64 * 1024 * 1024;

    private const MAX_MAX_FILES = 1_000_000;

    private const MIN_MAX_FILE_BYTES = 1_024;

    private const MAX_MAX_FILE_BYTES = 256 * 1024 * 1024;

    /**
     * @param string $directory    spool directory; created on the first write when it does not exist
     * @param int    $maxFiles     spool files the directory may hold
     * @param int    $maxFileBytes size of one spool file
     * @param int    $minFreeBytes free space that must remain on the file system after a write
     *
     * @throws LogicException when a value is invalid
     */
    public function __construct(
        public string $directory,
        public int $maxFiles = self::DEFAULT_MAX_FILES,
        public int $maxFileBytes = self::DEFAULT_MAX_FILE_BYTES,
        public int $minFreeBytes = self::DEFAULT_MIN_FREE_BYTES,
    ) {
        if ('' === $directory || str_contains($directory, "\0")) {
            throw new LogicException('The spool directory must be a non-empty path.');
        }

        if ($maxFiles < 1 || $maxFiles > self::MAX_MAX_FILES) {
            throw new LogicException(\sprintf('The maximum number of spool files must be between 1 and %d, got %d.', self::MAX_MAX_FILES, $maxFiles));
        }

        if ($maxFileBytes < self::MIN_MAX_FILE_BYTES || $maxFileBytes > self::MAX_MAX_FILE_BYTES) {
            throw new LogicException(\sprintf('The maximum spool file size must be between %d and %d bytes, got %d.', self::MIN_MAX_FILE_BYTES, self::MAX_MAX_FILE_BYTES, $maxFileBytes));
        }

        if ($minFreeBytes < 0) {
            throw new LogicException(\sprintf('The free space to keep must not be negative, got %d bytes.', $minFreeBytes));
        }
    }
}
