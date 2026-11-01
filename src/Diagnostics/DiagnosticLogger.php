<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

use Psr\Log\LoggerInterface;
use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;
use Tracewick\Transport\AtomicFile;

/**
 * Reports what goes wrong inside Tracewick to the application's PSR-3 logger, at most once per
 * interval for each message on a host.
 *
 * Tracewick logs at warning and below: a profiler that cannot deliver is not an error of the
 * application, and must not wake whoever is on call. Filter the messages by level or channel in the
 * logger itself.
 *
 * The interval is kept in a small file per message, so that it holds across PHP-FPM requests and
 * workers, which share no memory. The files are only touched when there is something to log. The
 * next message written after a quiet interval carries the number of copies suppressed in between
 * as `suppressed` in its context. Without a logger nothing happens, and an exception thrown by the
 * logger is swallowed (ADR 5).
 */
final readonly class DiagnosticLogger
{
    public const DEFAULT_INTERVAL_MS = 60_000;

    /**
     * @param ?string $directory  where the interval of each message is kept; the system temporary
     *                            directory by default
     * @param int     $intervalMs the shortest time between two copies of one message
     */
    public function __construct(
        private ?LoggerInterface $logger = null,
        private ?string $directory = null,
        private int $intervalMs = self::DEFAULT_INTERVAL_MS,
        private Clock $clock = new SystemClock(),
    ) {}

    /**
     * Whether a PSR-3 logger will receive messages.
     */
    public function hasLogger(): bool
    {
        return $this->logger instanceof LoggerInterface;
    }

    /**
     * @param string               $level   a {@see \Psr\Log\LogLevel} constant
     * @param string               $message message with PSR-3 placeholders; the same text is the
     *                                      same message for the interval
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->logger instanceof LoggerInterface) {
            return;
        }

        try {
            $suppressed = $this->suppressedSinceLastCopy($level . ' ' . $message);
            if (null === $suppressed) {
                return;
            }

            if (0 !== $suppressed) {
                $context['suppressed'] = $suppressed;
            }

            $this->logger->log($level, $message, $context);
        } catch (\Throwable) {
            // The application's logger must not break the application through Tracewick.
        }
    }

    /**
     * Records this copy of the message and returns how many copies were suppressed before it, or
     * null when this one is suppressed as well.
     */
    private function suppressedSinceLastCopy(string $message): ?int
    {
        $path = \sprintf('%s/tracewick-log-%s', $this->directory ?? sys_get_temp_dir(), md5($message));
        $nowNs = $this->clock->nowNs();
        $line = @file_get_contents($path);
        if (false === $line || 1 !== preg_match('/^(?<writtenNs>\d{1,19}) (?<suppressed>\d{1,9})\n$/D', $line, $matches)) {
            AtomicFile::write($path, \sprintf("%d 0\n", $nowNs));

            return 0;
        }

        $suppressed = (int) $matches['suppressed'];
        if ($nowNs - (int) $matches['writtenNs'] < $this->intervalMs * 1_000_000) {
            AtomicFile::write($path, \sprintf("%s %d\n", $matches['writtenNs'], $suppressed + 1));

            return null;
        }

        AtomicFile::write($path, \sprintf("%d 0\n", $nowNs));

        return $suppressed;
    }
}
