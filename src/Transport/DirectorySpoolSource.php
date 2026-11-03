<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * Reads the spool directory that a {@see SpoolTransport} writes, safely alongside other readers.
 *
 * A file is claimed by renaming `X.spool` to `.X.spool.claimed-<reader id>-<epoch>` in the same
 * directory (or shard). The epoch in the name is the claim start time, so abandoned claims are
 * detected without relying on mtime after rename ([docs/spool-format.md](../../docs/spool-format.md)).
 * Files larger than {@see SpoolReaderConfig::$maxFileBytes} are rejected without loading them.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final class DirectorySpoolSource implements SpoolSource
{
    public const REJECTED_DIRECTORY = 'rejected';

    private const CLAIM_MARKER = '.claimed-';

    private const TEMPORARY_SUFFIX = '.tmp';

    /**
     * Relative spool paths (possibly `ab/name.spool`) found by the last scan, oldest first.
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

    public function acknowledge(ClaimedSpoolFile $file): bool
    {
        return @unlink($file->handle);
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
     * @return list<string>
     */
    private function scan(): array
    {
        $files = [];
        $this->scanDirectory($this->config->directory, '', $files);
        sort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @param list<string> $files
     */
    private function scanDirectory(string $directory, string $prefix, array &$files): void
    {
        $entries = @scandir($directory);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $relative = '' === $prefix ? $entry : $prefix . '/' . $entry;
            $path = $directory . '/' . $entry;

            if (is_dir($path)) {
                if (self::REJECTED_DIRECTORY === $entry && '' === $prefix) {
                    continue;
                }

                if (1 === preg_match('/^[0-9a-f]{2}$/D', $entry)) {
                    $this->scanDirectory($path, $relative, $files);
                }

                continue;
            }

            if (SpoolTransport::isSpoolFile($entry)) {
                $files[] = $relative;
            } elseif (null !== ($name = $this->claimedRelativeName($entry, $prefix))) {
                if ($this->isClaimAbandoned($entry, $prefix) && @rename($path, $this->path($name))) {
                    $files[] = $name;
                }
            } elseif (str_starts_with($entry, '.') && str_ends_with($entry, self::TEMPORARY_SUFFIX) && $this->isOlderThan($path, $this->config->staleTemporaryMs)) {
                @unlink($path);
            }
        }
    }

    private function tryClaim(string $name): ?ClaimedSpoolFile
    {
        $source = $this->path($name);
        $size = @filesize($source);
        if (false !== $size && $size > $this->config->maxFileBytes) {
            $oversized = $this->claimPath($name);
            if (@rename($source, $oversized)) {
                $this->reject(new ClaimedSpoolFile($name, '', $oversized));
            }

            return null;
        }

        $claimed = $this->claimPath($name);
        if (!@rename($source, $claimed)) {
            return null;
        }

        $bytes = @file_get_contents($claimed);
        if (false === $bytes) {
            @rename($claimed, $source);

            return null;
        }

        if (\strlen($bytes) > $this->config->maxFileBytes) {
            $this->reject(new ClaimedSpoolFile($name, $bytes, $claimed));

            return null;
        }

        return new ClaimedSpoolFile($name, $bytes, $claimed);
    }

    private function claimPath(string $name): string
    {
        $epoch = intdiv($this->clock->nowNs(), 1_000_000_000);
        $dir = \dirname($this->path($name));
        $base = basename($name);

        return $dir . '/.' . $base . self::CLAIM_MARKER . $this->config->readerId . '-' . $epoch;
    }

    /**
     * Relative spool name inside a claimed basename, or null.
     */
    private function claimedRelativeName(string $entry, string $prefix): ?string
    {
        $marker = strpos($entry, SpoolTransport::FILE_SUFFIX . self::CLAIM_MARKER);
        if (!str_starts_with($entry, '.') || false === $marker) {
            return null;
        }

        $base = substr($entry, 1, $marker - 1 + \strlen(SpoolTransport::FILE_SUFFIX));
        if (!SpoolTransport::isSpoolFile($base)) {
            return null;
        }

        return '' === $prefix ? $base : $prefix . '/' . $base;
    }

    private function isClaimAbandoned(string $entry, string $prefix): bool
    {
        if (1 === preg_match('/\.claimed-[A-Za-z0-9._-]+-(\d+)$/D', $entry, $matches)) {
            $claimedAtMs = (int) $matches[1] * 1_000;

            return intdiv($this->clock->nowNs(), 1_000_000) - $claimedAtMs > $this->config->staleClaimMs;
        }

        // Legacy claims without an epoch: fall back to mtime (pre-wave-3b).
        return $this->isOlderThan(('' === $prefix ? $this->config->directory : $this->config->directory . '/' . $prefix) . '/' . $entry, $this->config->staleClaimMs);
    }

    private function isOlderThan(string $path, int $ageMs): bool
    {
        clearstatcache(true, $path);
        $modified = @filemtime($path);

        return false !== $modified && intdiv($this->clock->nowNs(), 1_000_000) - $modified * 1_000 > $ageMs;
    }

    private function moveToRejected(ClaimedSpoolFile $file): bool
    {
        if (0 === $this->config->maxRejectedFiles) {
            return false;
        }

        $rejected = $this->path(self::REJECTED_DIRECTORY);
        if (!is_dir($rejected)) {
            @mkdir($rejected, 0o700);
        }

        return @rename($file->handle, $rejected . '/' . basename($file->name));
    }

    private function trimRejected(): void
    {
        $rejected = $this->path(self::REJECTED_DIRECTORY);
        $entries = @scandir($rejected);
        $files = array_values(array_filter(false === $entries ? [] : $entries, SpoolTransport::isSpoolFile(...)));
        sort($files, \SORT_STRING);
        foreach (\array_slice($files, 0, max(0, \count($files) - $this->config->maxRejectedFiles)) as $old) {
            @unlink($rejected . '/' . $old);
        }
    }

    private function path(string $entry): string
    {
        return $this->config->directory . '/' . $entry;
    }
}
