<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;
use Tracewick\Sink\SpoolAggregationKey;

/**
 * Writes each payload as one file into a local spool directory, for a separate process to deliver.
 *
 * The payload body is written as is, under a temporary name that starts with a dot, and renamed
 * into place once complete, so readers never see a partial file. Before writing, the transport
 * checks the limits of its {@see SpoolConfig} with a soft-cached file count; a payload that would
 * exceed one is dropped and reported as skipped or rejected. Directories are created with mode
 * `0700` and files with `0600`. When {@see self::PARAM_AGGREGATION_KEY_HASH} is set, the file
 * lives under a two-hex shard and the hash appears in the name.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final readonly class SpoolTransport implements Transport
{
    public const FILE_SUFFIX = '.spool';

    public const PARAM_AGGREGATION_KEY_HASH = 'aggregation_key_hash';

    private const TEMPORARY_SUFFIX = '.tmp';

    private const HASH_PATTERN = '/^[0-9a-f]{16}$/D';

    private SpoolAdmissionState $admission;

    public function __construct(
        private SpoolConfig $config,
        private Clock $clock = new SystemClock(),
        ?SpoolAdmissionState $admission = null,
    ) {
        $this->admission = $admission ?? new SpoolAdmissionState();
    }

    public function send(Payload $payload): Delivery
    {
        try {
            return $this->write($payload->body, $payload->parameters[self::PARAM_AGGREGATION_KEY_HASH] ?? null);
        } catch (\Throwable $e) {
            return Delivery::failed($e->getMessage());
        }
    }

    /**
     * Whether a directory entry (basename) is a spool file: ends in .spool and does not start with a dot.
     */
    public static function isSpoolFile(string $name): bool
    {
        $base = basename($name);

        return !str_starts_with($base, '.') && str_ends_with($base, self::FILE_SUFFIX);
    }

    private function write(string $body, mixed $aggregationKeyHash): Delivery
    {
        $size = \strlen($body);
        if ($size > $this->config->maxFileBytes) {
            return Delivery::rejected(\sprintf('The spool file of %d bytes exceeds the limit of %d bytes.', $size, $this->config->maxFileBytes));
        }

        $hash = null;
        if (null !== $aggregationKeyHash) {
            if (!\is_string($aggregationKeyHash) || 1 !== preg_match(self::HASH_PATTERN, $aggregationKeyHash)) {
                return Delivery::rejected('The aggregation key hash must be 16 lowercase hexadecimal digits.');
            }

            $hash = $aggregationKeyHash;
        }

        $root = $this->config->directory;
        if (!$this->ensureDirectory($root)) {
            return Delivery::failed(\sprintf('Cannot create the spool directory "%s".', $root));
        }

        $directory = $root;
        if (null !== $hash) {
            $directory = $root . '/' . SpoolAggregationKey::shard($hash);
            if (!$this->ensureDirectory($directory)) {
                return Delivery::failed(\sprintf('Cannot create the spool shard directory "%s".', $directory));
            }
        }

        if ($this->isFull($root)) {
            return Delivery::skipped(\sprintf('The spool directory already holds %d files.', $this->config->maxFiles));
        }

        $free = @disk_free_space($directory);
        if (false !== $free && $free - $size < $this->config->minFreeBytes) {
            return Delivery::skipped(\sprintf('Writing would leave less than %d bytes free in the spool directory.', $this->config->minFreeBytes));
        }

        $name = null === $hash
            ? \sprintf('%020d-%s%s', $this->clock->nowNs(), bin2hex(random_bytes(8)), self::FILE_SUFFIX)
            : \sprintf('%020d-%s-%s%s', $this->clock->nowNs(), $hash, bin2hex(random_bytes(8)), self::FILE_SUFFIX);
        $temporary = $directory . '/.' . $name . self::TEMPORARY_SUFFIX;
        $final = $directory . '/' . $name;

        if (@file_put_contents($temporary, $body) !== $size || !@chmod($temporary, 0o600) || !@rename($temporary, $final)) {
            @unlink($temporary);

            return Delivery::failed(\sprintf('Cannot write the spool file "%s".', $name));
        }

        $this->admission->recordWrite();

        return Delivery::delivered();
    }

    private function ensureDirectory(string $directory): bool
    {
        return is_dir($directory) || (@mkdir($directory, 0o700, true) || is_dir($directory));
    }

    /**
     * Soft-cached count of spool files under the root (including shards).
     */
    private function isFull(string $root): bool
    {
        $nowNs = $this->clock->nowNs();
        if ($this->admission->needsRefresh($nowNs)) {
            $this->admission->refresh($this->countFiles($root), $nowNs);
        }

        return $this->admission->cachedCount >= $this->config->maxFiles;
    }

    private function countFiles(string $root): int
    {
        $entries = @scandir($root, \SCANDIR_SORT_NONE);
        if (false === $entries) {
            throw new TransportException(\sprintf('Cannot read the spool directory "%s".', $root));
        }

        $files = 0;
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $root . '/' . $entry;
            if (is_dir($path) && 1 === preg_match('/^[0-9a-f]{2}$/D', $entry)) {
                $files += $this->countFilesIn($path);
                if ($files >= $this->config->maxFiles) {
                    return $files;
                }

                continue;
            }

            if (self::isSpoolFile($entry) && ++$files >= $this->config->maxFiles) {
                return $files;
            }
        }

        return $files;
    }

    private function countFilesIn(string $directory): int
    {
        $entries = @scandir($directory, \SCANDIR_SORT_NONE);
        if (false === $entries) {
            return 0;
        }

        $files = 0;
        foreach ($entries as $entry) {
            if (self::isSpoolFile($entry)) {
                ++$files;
            }
        }

        return $files;
    }
}
