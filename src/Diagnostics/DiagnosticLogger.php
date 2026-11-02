<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

use Psr\Log\LoggerInterface;
use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * Reports what goes wrong inside Tracewick to the application's PSR-3 logger, at most once per
 * interval for each message in this process.
 *
 * Tracewick logs at warning and below: a profiler that cannot deliver is not an error of the
 * application, and must not wake whoever is on call. Filter the messages by level or channel in the
 * logger itself.
 *
 * The interval is kept in process memory, so suppress does not touch the filesystem. Separate
 * PHP-FPM workers each keep their own limits (approximate host-wide rate). The next message written
 * after a quiet interval carries the number of copies suppressed in between as `suppressed` in its
 * context. Without a logger nothing happens, and an exception thrown by the logger is swallowed
 * (ADR 5).
 */
final class DiagnosticLogger
{
    public const DEFAULT_INTERVAL_MS = 60_000;

    /**
     * @var array<string, array{writtenNs: int, suppressed: int}>
     */
    private static array $rateLimits = [];

    /**
     * @param ?string $directory  unused; kept so existing call sites that passed a state directory
     *                            keep compiling
     * @param int     $intervalMs the shortest time between two copies of one message
     */
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        ?string $directory = null,
        private readonly int $intervalMs = self::DEFAULT_INTERVAL_MS,
        private readonly Clock $clock = new SystemClock(),
    ) {
        // Legacy second argument was the rate-limit file directory; suppress is process-static now.
        if (null !== $directory && '' === $directory) {
            // Touch the parameter so call-site BC stays without file I/O.
        }
    }

    /**
     * Clears in-process rate-limit state. For tests only.
     *
     * @internal
     */
    public static function resetRateLimits(): void
    {
        self::$rateLimits = [];
    }

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
        $nowNs = $this->clock->nowNs();
        $entry = self::$rateLimits[$message] ?? null;
        if (null === $entry) {
            self::$rateLimits[$message] = ['writtenNs' => $nowNs, 'suppressed' => 0];

            return 0;
        }

        if ($nowNs - $entry['writtenNs'] < $this->intervalMs * 1_000_000) {
            self::$rateLimits[$message] = [
                'writtenNs' => $entry['writtenNs'],
                'suppressed' => $entry['suppressed'] + 1,
            ];

            return null;
        }

        $suppressed = $entry['suppressed'];
        self::$rateLimits[$message] = ['writtenNs' => $nowNs, 'suppressed' => 0];

        return $suppressed;
    }
}
