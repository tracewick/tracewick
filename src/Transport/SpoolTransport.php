<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * Writes each payload as one file into a local spool directory, for a separate process to deliver.
 *
 * The payload body is written as is, under a temporary name that starts with a dot, and renamed
 * into place once complete, so readers never see a partial file. Before writing, the transport
 * checks the limits of its {@see SpoolConfig}; a payload that would exceed one is dropped and
 * reported as skipped or rejected. The transport never waits for a reader and never deletes
 * spool files. Parameters of the payload are ignored: the body is the complete spool file.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final readonly class SpoolTransport implements Transport
{
    public const FILE_SUFFIX = '.spool';

    private const TEMPORARY_SUFFIX = '.tmp';

    public function __construct(
        private SpoolConfig $config,
        private Clock $clock = new SystemClock(),
    ) {}

    public function send(Payload $payload): Delivery
    {
        try {
            return $this->write($payload->body);
        } catch (\Throwable $e) {
            return Delivery::failed($e->getMessage());
        }
    }

    /**
     * Whether a directory entry is a spool file: ends in .spool and does not start with a dot.
     */
    public static function isSpoolFile(string $name): bool
    {
        return !str_starts_with($name, '.') && str_ends_with($name, self::FILE_SUFFIX);
    }

    private function write(string $body): Delivery
    {
        $size = \strlen($body);
        if ($size > $this->config->maxFileBytes) {
            return Delivery::rejected(\sprintf('The spool file of %d bytes exceeds the limit of %d bytes.', $size, $this->config->maxFileBytes));
        }

        $directory = $this->config->directory;

        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            return Delivery::failed(\sprintf('Cannot create the spool directory "%s".', $directory));
        }

        if ($this->isFull($directory)) {
            return Delivery::skipped(\sprintf('The spool directory already holds %d files.', $this->config->maxFiles));
        }

        $free = @disk_free_space($directory);
        if (false !== $free && $free - $size < $this->config->minFreeBytes) {
            return Delivery::skipped(\sprintf('Writing would leave less than %d bytes free in the spool directory.', $this->config->minFreeBytes));
        }

        $name = \sprintf('%020d-%s%s', $this->clock->nowNs(), bin2hex(random_bytes(8)), self::FILE_SUFFIX);
        $temporary = $directory . '/.' . $name . self::TEMPORARY_SUFFIX;

        if (@file_put_contents($temporary, $body) !== $size || !@rename($temporary, $directory . '/' . $name)) {
            @unlink($temporary);

            return Delivery::failed(\sprintf('Cannot write the spool file "%s".', $name));
        }

        return Delivery::delivered();
    }

    /**
     * Counts spool files and stops as soon as the limit is reached.
     */
    private function isFull(string $directory): bool
    {
        $handle = @opendir($directory);
        if (false === $handle) {
            throw new TransportException(\sprintf('Cannot read the spool directory "%s".', $directory));
        }

        try {
            $files = 0;
            while (false !== ($entry = readdir($handle))) {
                if (self::isSpoolFile($entry) && ++$files >= $this->config->maxFiles) {
                    return true;
                }
            }

            return false;
        } finally {
            closedir($handle);
        }
    }
}
