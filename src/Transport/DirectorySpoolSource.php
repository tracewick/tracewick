<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * Reads the spool directory that a {@see SpoolTransport} writes, safely alongside other readers.
 *
 * A file is claimed by renaming `X.spool` to `.X.spool.claimed-<reader id>`: the rename is atomic,
 * so exactly one reader gets each file. Claims older than the configured age are returned to the
 * spool, which recovers files from readers that died, and temporary files of crashed writers are
 * deleted. Rejected files move to the `rejected/` subdirectory, which keeps a bounded number of
 * them for inspection.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final class DirectorySpoolSource implements SpoolSource
{
    public const REJECTED_DIRECTORY = 'rejected';

    private const CLAIM_MARKER = '.claimed-';

    private const TEMPORARY_SUFFIX = '.tmp';

    /**
     * Spool files found by the last scan that this reader has not tried yet, oldest first.
     *
     * @var list<string>
     */
    private array $queue = [];

    public function __construct(
        private readonly SpoolReaderConfig $config,
        private readonly Clock $clock = new SystemClock(),
    ) {}

    /**
     * @phpstan-impure
     */
    public function claim(): ?ClaimedSpoolFile
    {
        try {
            // Files left from an earlier scan may be gone; when they are, scan once more.
            $scanned = false;
            do {
                if ([] === $this->queue) {
                    $this->queue = $this->scan();
                    $scanned = true;
                }

                while (null !== ($name = array_shift($this->queue))) {
                    $file = $this->tryClaim($name);
                    if ($file instanceof ClaimedSpoolFile) {
                        return $file;
                    }
                }
            } while (!$scanned);
        } catch (\Throwable) {
            $this->queue = [];
        }

        return null;
    }

    public function acknowledge(ClaimedSpoolFile $file): void
    {
        @unlink($file->handle);
    }

    public function release(ClaimedSpoolFile $file): void
    {
        @rename($file->handle, $this->path($file->name));
    }

    public function reject(ClaimedSpoolFile $file): void
    {
        try {
            if ($this->moveToRejected($file)) {
                $this->trimRejected();
            } else {
                @unlink($file->handle);
            }
        } catch (\Throwable) {
            @unlink($file->handle);
        }
    }

    /**
     * Lists the spool files, returning abandoned claims to the spool and deleting abandoned
     * temporary files on the way.
     *
     * @return list<string>
     */
    private function scan(): array
    {
        $entries = @scandir($this->config->directory);
        if (false === $entries) {
            return [];
        }

        $files = [];
        foreach ($entries as $entry) {
            if (SpoolTransport::isSpoolFile($entry)) {
                $files[] = $entry;
            } elseif (null !== ($name = $this->claimedName($entry))) {
                if ($this->isOlderThan($entry, $this->config->staleClaimMs) && @rename($this->path($entry), $this->path($name))) {
                    $files[] = $name;
                }
            } elseif (str_starts_with($entry, '.') && str_ends_with($entry, self::TEMPORARY_SUFFIX) && $this->isOlderThan($entry, $this->config->staleTemporaryMs)) {
                @unlink($this->path($entry));
            }
        }

        sort($files, \SORT_STRING);

        return $files;
    }

    private function tryClaim(string $name): ?ClaimedSpoolFile
    {
        $claimed = $this->path('.' . $name . self::CLAIM_MARKER . $this->config->readerId);
        if (!@rename($this->path($name), $claimed)) {
            // Another reader claimed it first.
            return null;
        }

        // A fresh modification time tells other readers that the claim is alive.
        @touch($claimed, intdiv($this->clock->nowNs(), 1_000_000_000));
        $bytes = @file_get_contents($claimed);

        return false === $bytes ? null : new ClaimedSpoolFile($name, $bytes, $claimed);
    }

    /**
     * Returns the spool file name inside a claimed file name, or null for any other entry.
     */
    private function claimedName(string $entry): ?string
    {
        $marker = strpos($entry, SpoolTransport::FILE_SUFFIX . self::CLAIM_MARKER);
        if (!str_starts_with($entry, '.') || false === $marker) {
            return null;
        }

        $name = substr($entry, 1, $marker - 1 + \strlen(SpoolTransport::FILE_SUFFIX));

        return SpoolTransport::isSpoolFile($name) ? $name : null;
    }

    private function isOlderThan(string $entry, int $ageMs): bool
    {
        $path = $this->path($entry);
        clearstatcache(true, $path);
        $modified = @filemtime($path);

        return false !== $modified && intdiv($this->clock->nowNs(), 1_000_000) - $modified * 1_000 > $ageMs;
    }

    /**
     * Moves a file to the rejected directory. Returns false when the file could not be kept.
     */
    private function moveToRejected(ClaimedSpoolFile $file): bool
    {
        if (0 === $this->config->maxRejectedFiles) {
            return false;
        }

        $rejected = $this->path(self::REJECTED_DIRECTORY);
        // The second is_dir() covers another reader creating the directory at the same time.
        if (!is_dir($rejected) && !@mkdir($rejected, 0o777) && !is_dir($rejected)) {
            return false;
        }

        $target = $rejected . '/' . $file->name;

        return @rename($file->handle, $target);
    }

    /**
     * Deletes the oldest rejected files beyond the configured number.
     */
    private function trimRejected(): void
    {
        $rejected = $this->path(self::REJECTED_DIRECTORY);
        $entries = @scandir($rejected);
        $files = array_filter(false === $entries ? [] : $entries, SpoolTransport::isSpoolFile(...));
        foreach (\array_slice($files, 0, max(0, \count($files) - $this->config->maxRejectedFiles)) as $old) {
            @unlink($rejected . '/' . $old);
        }
    }

    private function path(string $entry): string
    {
        return $this->config->directory . '/' . $entry;
    }
}
