<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Shares the circuit state between the processes of a host through a small file, so that all
 * PHP-FPM workers stop sending to a destination that keeps failing, not each of them on its own.
 *
 * The file holds one line, the failure count and the end of the open period or `-`. It is
 * replaced by an atomic rename, so a reader never sees half of it. {@see update()} takes an
 * exclusive flock on a sibling lock file so concurrent workers do not race the trial delivery.
 * A missing or unreadable file means a closed circuit; a write that cannot persist leaves the
 * previous state as it was.
 */
final readonly class FileCircuitBreakerStore implements CircuitBreakerStore
{
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
        $line = @file_get_contents($this->path);
        if (false === $line || 1 !== preg_match('/^(?<failures>\d{1,9}) (?<openUntil>\d{1,19}|-)\n$/D', $line, $matches)) {
            return new CircuitBreakerState();
        }

        return new CircuitBreakerState((int) $matches['failures'], '-' === $matches['openUntil'] ? null : (int) $matches['openUntil']);
    }

    public function save(CircuitBreakerState $state): void
    {
        self::ensureDirectory(\dirname($this->path));
        AtomicFile::write($this->path, \sprintf("%d %s\n", $state->consecutiveFailures, $state->openUntilNs ?? '-'));
    }

    public function update(callable $updater): CircuitBreakerState
    {
        self::ensureDirectory(\dirname($this->path));
        $lockPath = $this->path . '.lock';
        $lock = @fopen($lockPath, 'c+');
        if (false === $lock) {
            $state = $updater($this->load());
            $this->save($state);

            return $state;
        }

        try {
            if (!@flock($lock, \LOCK_EX)) {
                $state = $updater($this->load());
                $this->save($state);

                return $state;
            }

            $state = $updater($this->load());
            $this->save($state);

            return $state;
        } finally {
            @flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Whether the last {@see save()} content is what {@see load()} would read now.
     */
    public function persisted(CircuitBreakerState $expected): bool
    {
        $loaded = $this->load();

        return $loaded->consecutiveFailures === $expected->consecutiveFailures
            && $loaded->openUntilNs === $expected->openUntilNs;
    }

    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        @mkdir($directory, 0o700, true);
    }
}
