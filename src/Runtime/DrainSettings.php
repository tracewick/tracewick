<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Exception\LogicException;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Transport\SpoolReaderConfig;

/**
 * Settings of the `tracewick-drain` command, read from its options and environment variables.
 *
 * An option wins over the environment variable of the same setting. The Pyroscope password is
 * read from the environment only, so that it does not show up in the process list.
 *
 * @internal
 */
final readonly class DrainSettings
{
    public const DEFAULT_SLEEP_SECONDS = 1;

    private const MAX_SLEEP_SECONDS = 3_600;

    private const UNITS = ['K' => 1_024, 'M' => 1_024 ** 2, 'G' => 1_024 ** 3];

    /** Application name the Pyroscope configuration requires; forwarding uses the name in each file. */
    private const PYROSCOPE_APPLICATION = 'tracewick-drain';

    /**
     * @param ?int $limit            files to handle before exiting
     * @param ?int $memoryLimit      bytes of memory above which the command exits
     * @param ?int $timeLimitSeconds seconds to run before exiting
     * @param int  $sleepSeconds     pause while the spool is empty
     */
    public function __construct(
        public SpoolReaderConfig $reader,
        public PyroscopeConfig $pyroscope,
        public bool $once,
        public ?int $limit,
        public ?int $memoryLimit,
        public ?int $timeLimitSeconds,
        public int $sleepSeconds,
    ) {}

    /**
     * @param array<string, string> $options     option values by name, '' for a flag
     * @param array<string, string> $environment environment variables
     *
     * @throws LogicException when a setting is missing or invalid
     */
    public static function fromOptions(array $options, array $environment): self
    {
        $value = static fn(string $option, string $variable): ?string => self::nonEmpty($options[$option] ?? null) ?? self::nonEmpty($environment[$variable] ?? null);

        $directory = $value('spool-dir', 'TRACEWICK_SPOOL_DIR') ?? throw new LogicException('The spool directory is required.');
        $url = $value('pyroscope-url', 'TRACEWICK_PYROSCOPE_URL') ?? throw new LogicException('The Pyroscope URL is required.');
        $readerId = self::nonEmpty($options['reader-id'] ?? null);

        $memoryLimit = self::nonEmpty($options['memory-limit'] ?? null);
        $limit = self::nonEmpty($options['limit'] ?? null);
        $timeLimit = self::nonEmpty($options['time-limit'] ?? null);
        $sleep = self::nonEmpty($options['sleep'] ?? null);

        return new self(
            null === $readerId ? new SpoolReaderConfig($directory) : new SpoolReaderConfig($directory, $readerId),
            new PyroscopeConfig(
                $url,
                self::PYROSCOPE_APPLICATION,
                $value('pyroscope-user', 'TRACEWICK_PYROSCOPE_USER'),
                self::nonEmpty($environment['TRACEWICK_PYROSCOPE_PASSWORD'] ?? null),
                $value('pyroscope-tenant', 'TRACEWICK_PYROSCOPE_TENANT'),
            ),
            isset($options['once']),
            null === $limit ? null : self::integer('limit', $limit, 1, \PHP_INT_MAX),
            null === $memoryLimit ? null : self::bytes($memoryLimit),
            null === $timeLimit ? null : self::integer('time-limit', $timeLimit, 1, \PHP_INT_MAX),
            null === $sleep ? self::DEFAULT_SLEEP_SECONDS : self::integer('sleep', $sleep, 0, self::MAX_SLEEP_SECONDS),
        );
    }

    private static function nonEmpty(?string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    private static function integer(string $option, string $value, int $min, int $max): int
    {
        // Only the canonical decimal form survives the round trip, so "1.5", "07" and overflows fail.
        $integer = (int) $value;
        if ((string) $integer !== $value || $integer < $min || $integer > $max) {
            throw new LogicException(\sprintf('--%s must be a whole number from %d to %d, got "%s".', $option, $min, $max, $value));
        }

        return $integer;
    }

    /**
     * Reads a size such as 512K, 128M or 1G.
     */
    private static function bytes(string $value): int
    {
        $multiplier = self::UNITS[strtoupper(substr($value, -1))] ?? 1;
        $number = 1 === $multiplier ? $value : substr($value, 0, -1);
        $integer = (int) $number;
        if ((string) $integer !== $number || $integer < 1 || $integer > intdiv(\PHP_INT_MAX, $multiplier)) {
            throw new LogicException(\sprintf('--memory-limit must be a size such as 128M, got "%s".', $value));
        }

        return $integer * $multiplier;
    }
}
