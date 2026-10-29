<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Exception\LogicException;

/**
 * Where a {@see DirectorySpoolSource} reads spool files and how it shares them with other readers.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final readonly class SpoolReaderConfig
{
    public const DEFAULT_STALE_CLAIM_MS = 600_000;

    public const DEFAULT_STALE_TEMPORARY_MS = 3_600_000;

    public const DEFAULT_MAX_REJECTED_FILES = 100;

    private const READER_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    private const MAX_AGE_MS = 7 * 24 * 3_600_000;

    public string $readerId;

    /**
     * @param string  $directory        spool directory, as configured for the writers
     * @param ?string $readerId         unique among the readers of the directory; host name and
     *                                  process id by default
     * @param int     $staleClaimMs     age after which a claim is considered abandoned and the file
     *                                  is returned to the spool; longer than one delivery takes
     * @param int     $staleTemporaryMs age after which a writer's temporary file is deleted
     * @param int     $maxRejectedFiles rejected files kept for inspection; older ones are deleted
     *
     * @throws LogicException when a value is invalid
     */
    public function __construct(
        public string $directory,
        ?string $readerId = null,
        public int $staleClaimMs = self::DEFAULT_STALE_CLAIM_MS,
        public int $staleTemporaryMs = self::DEFAULT_STALE_TEMPORARY_MS,
        public int $maxRejectedFiles = self::DEFAULT_MAX_REJECTED_FILES,
    ) {
        if ('' === $directory || str_contains($directory, "\0")) {
            throw new LogicException('The spool directory must be a non-empty path.');
        }

        $readerId ??= self::readerIdFor((string) gethostname(), (int) getmypid());
        if (1 !== preg_match(self::READER_ID_PATTERN, $readerId)) {
            throw new LogicException(\sprintf('The reader id must be 1 to 64 letters, digits, dots, underscores or dashes, got "%s".', $readerId));
        }

        $this->readerId = $readerId;

        if ($staleClaimMs < 1_000 || $staleClaimMs > self::MAX_AGE_MS) {
            throw new LogicException(\sprintf('The stale claim age must be between 1 second and 7 days, got %d ms.', $staleClaimMs));
        }

        if ($staleTemporaryMs < 1_000 || $staleTemporaryMs > self::MAX_AGE_MS) {
            throw new LogicException(\sprintf('The stale temporary file age must be between 1 second and 7 days, got %d ms.', $staleTemporaryMs));
        }

        if ($maxRejectedFiles < 0 || $maxRejectedFiles > 100_000) {
            throw new LogicException(\sprintf('The number of rejected files to keep must be between 0 and 100000, got %d.', $maxRejectedFiles));
        }
    }

    /**
     * Builds a reader id from a host name and a process id, replacing characters a file name
     * should not carry and shortening long host names so that the process id is kept.
     */
    public static function readerIdFor(string $host, int $pid): string
    {
        $suffix = '-' . $pid;

        return substr((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $host), 0, 64 - \strlen($suffix)) . $suffix;
    }
}
