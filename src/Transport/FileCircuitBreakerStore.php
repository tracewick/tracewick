<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Shares the circuit state between processes of a host through a small file (opt-in via
 * {@see CircuitBreakerConfig::$stateDirectory}).
 *
 * The file holds one line: failure count, open deadline or `-`, and a generation. It is replaced
 * by an atomic rename. {@see acquire()} / {@see complete()} take a non-blocking exclusive flock on
 * a sibling lock file (with a short retry budget). When the lock cannot be taken, acquire denies
 * the send (Skipped) and complete returns false — never an unlocked read-modify-write.
 */
final readonly class FileCircuitBreakerStore implements CircuitBreakerStore
{
    /** Microseconds to keep trying {@see \LOCK_NB} before degrading. */
    private const LOCK_WAIT_BUDGET_US = 50_000;

    /** Pause between non-blocking lock attempts. */
    private const LOCK_RETRY_SLEEP_US = 1_000;

    public function __construct(private string $path) {}

    /**
     * A store in the directory for the given destination, so that destinations do not share state.
     *
     * Creates the directory with mode 0700 when it is missing.
     *
     * @param string $destination anything that identifies the destination, such as its URL
     */
    public static function forDestination(string $directory, string $destination): self
    {
        self::ensureDirectory($directory);

        return new self(\sprintf('%s/tracewick-circuit-%s', $directory, md5($destination)));
    }

    public function load(): CircuitBreakerState
    {
        return $this->read();
    }

    public function acquire(int $nowNs, int $trialExtendMs, int $maxPauseMs): ?CircuitBreakerAttempt
    {
        return $this->withLock(function () use ($nowNs, $trialExtendMs, $maxPauseMs): ?CircuitBreakerAttempt {
            $state = $this->clamp($this->read(), $nowNs, $maxPauseMs);
            if (null !== $state->openUntilNs && $nowNs < $state->openUntilNs) {
                $this->write($state);

                return null;
            }

            $takingTrial = null !== $state->openUntilNs;
            $generation = $state->generation + 1;
            $next = $takingTrial
                ? new CircuitBreakerState($state->consecutiveFailures, $nowNs + $trialExtendMs * 1_000_000, $generation)
                : new CircuitBreakerState($state->consecutiveFailures, null, $generation);
            if (!$this->write($next)) {
                return null;
            }

            return new CircuitBreakerAttempt($generation, $state, $takingTrial);
        });
    }

    public function complete(int $generation, CircuitBreakerState $next): bool
    {
        $applied = false;
        $this->withLock(function () use ($generation, $next, &$applied): ?CircuitBreakerAttempt {
            $current = $this->read();
            if ($current->generation !== $generation) {
                return null;
            }

            if (!$this->write(new CircuitBreakerState($next->consecutiveFailures, $next->openUntilNs, $generation))) {
                return null;
            }
            $applied = true;

            return null;
        });

        return $applied;
    }

    /**
     * Whether the last written content is what {@see load()} would read now.
     */
    public function persisted(CircuitBreakerState $expected): bool
    {
        $loaded = $this->read();

        return $loaded->consecutiveFailures === $expected->consecutiveFailures
            && $loaded->openUntilNs === $expected->openUntilNs
            && $loaded->generation === $expected->generation;
    }

    /**
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T|null null when the lock could not be taken (acquire denials / complete false)
     */
    private function withLock(callable $body): mixed
    {
        self::ensureDirectory(\dirname($this->path));
        $lockPath = $this->path . '.lock';
        $lock = @fopen($lockPath, 'c+');
        if (false === $lock) {
            return null;
        }

        try {
            if (!$this->acquireExclusiveLock($lock)) {
                return null;
            }

            return $body();
        } finally {
            @flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    private function read(): CircuitBreakerState
    {
        $line = @file_get_contents($this->path);
        if (false === $line) {
            return new CircuitBreakerState();
        }

        if (1 === preg_match('/^(?<failures>\d{1,9}) (?<openUntil>\d{1,19}|-) (?<generation>\d{1,9})\n$/D', $line, $matches)) {
            return new CircuitBreakerState(
                (int) $matches['failures'],
                '-' === $matches['openUntil'] ? null : (int) $matches['openUntil'],
                (int) $matches['generation'],
            );
        }

        // Pre-S5 lines without a generation.
        if (1 === preg_match('/^(?<failures>\d{1,9}) (?<openUntil>\d{1,19}|-)\n$/D', $line, $matches)) {
            return new CircuitBreakerState((int) $matches['failures'], '-' === $matches['openUntil'] ? null : (int) $matches['openUntil']);
        }

        return new CircuitBreakerState();
    }

    private function write(CircuitBreakerState $state): bool
    {
        return AtomicFile::write(
            $this->path,
            \sprintf("%d %s %d\n", $state->consecutiveFailures, $state->openUntilNs ?? '-', $state->generation),
        );
    }

    private function clamp(CircuitBreakerState $state, int $nowNs, int $maxPauseMs): CircuitBreakerState
    {
        if (null === $state->openUntilNs) {
            return $state;
        }

        $latestAllowed = $nowNs + $maxPauseMs * 1_000_000;
        if ($state->openUntilNs <= $latestAllowed) {
            return $state;
        }

        return new CircuitBreakerState($state->consecutiveFailures, $latestAllowed, $state->generation);
    }

    /**
     * @param resource $lock
     */
    private function acquireExclusiveLock($lock): bool
    {
        $deadline = hrtime(true) + self::LOCK_WAIT_BUDGET_US * 1_000;
        while (!@flock($lock, \LOCK_EX | \LOCK_NB)) {
            if (hrtime(true) >= $deadline) {
                return false;
            }

            usleep(self::LOCK_RETRY_SLEEP_US);
        }

        return true;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        @mkdir($directory, 0o700, true);
    }
}
