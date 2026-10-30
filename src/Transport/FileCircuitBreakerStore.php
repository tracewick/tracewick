<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Shares the circuit state between the processes of a host through a small file, so that all
 * PHP-FPM workers stop sending to a destination that keeps failing, not each of them on its own.
 *
 * The file holds one line, the failure count and the end of the open period or `-`. It is
 * replaced by an atomic rename, so a reader never sees half of it. A missing or unreadable file
 * means a closed circuit, and a file that cannot be written leaves the state as it was.
 */
final readonly class FileCircuitBreakerStore implements CircuitBreakerStore
{
    public function __construct(private string $path) {}

    /**
     * A store in the directory for the given destination, so that destinations do not share state.
     *
     * @param string $destination anything that identifies the destination, such as its URL
     */
    public static function forDestination(string $directory, string $destination): self
    {
        return new self(\sprintf('%s/tracewick-circuit-%s', $directory, md5($destination)));
    }

    public function load(): CircuitBreakerState
    {
        $line = @file_get_contents($this->path);
        if (false === $line || 1 !== preg_match('/^(\d{1,9}) (\d{1,19}|-)\n$/D', $line, $matches)) {
            return new CircuitBreakerState();
        }

        return new CircuitBreakerState((int) $matches[1], '-' === $matches[2] ? null : (int) $matches[2]);
    }

    public function save(CircuitBreakerState $state): void
    {
        $line = \sprintf("%d %s\n", $state->consecutiveFailures, $state->openUntilNs ?? '-');
        $temporary = \sprintf('%s.%d.tmp', $this->path, getmypid());
        if (false === @file_put_contents($temporary, $line) || !@rename($temporary, $this->path)) {
            @unlink($temporary);
        }
    }
}
