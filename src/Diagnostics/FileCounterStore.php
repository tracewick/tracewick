<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

use Tracewick\Transport\AtomicFile;

/**
 * Counter store persisted as one JSON object under a non-blocking exclusive lock.
 *
 * When the lock cannot be opened or taken within a short budget, the increment is skipped (no
 * unlocked read-modify-write). Prefer {@see CounterStoreFactory}.
 *
 * @internal
 */
final readonly class FileCounterStore implements CounterStore
{
    /** Microseconds to keep trying {@see \LOCK_NB} before degrading. */
    private const LOCK_WAIT_BUDGET_US = 50_000;

    /** Pause between non-blocking lock attempts. */
    private const LOCK_RETRY_SLEEP_US = 1_000;

    /** @var \Closure(string): (resource|false) */
    private \Closure $openLock;

    /** @var \Closure(resource): bool */
    private \Closure $acquireExclusiveLock;

    /** @var \Closure(resource): void */
    private \Closure $releaseLock;

    /**
     * @param ?\Closure(string): (resource|false) $openLock
     * @param ?\Closure(resource): bool           $acquireExclusiveLock
     * @param ?\Closure(resource): void           $releaseLock
     *
     * @internal callables exist so tests cover lock failure without racing the filesystem
     */
    public function __construct(
        private string $path,
        ?\Closure $openLock = null,
        ?\Closure $acquireExclusiveLock = null,
        ?\Closure $releaseLock = null,
    ) {
        $this->openLock = $openLock ?? static fn(string $lockPath) => @fopen($lockPath, 'c+');
        $this->acquireExclusiveLock = $acquireExclusiveLock ?? static function ($handle): bool {
            /** @var resource $handle */
            return @flock($handle, \LOCK_EX | \LOCK_NB);
        };
        $this->releaseLock = $releaseLock ?? static function ($handle): void {
            /** @var resource $handle */
            @flock($handle, \LOCK_UN);
        };
    }

    public static function inDirectory(string $directory): self
    {
        self::ensureDirectory($directory);

        return new self($directory . '/tracewick-counters.json');
    }

    public function increment(string $key, int $by = 1): void
    {
        if ($by < 1 || '' === $key) {
            return;
        }

        $this->update(static function (array $counters) use ($key, $by): array {
            $counters[$key] = ($counters[$key] ?? 0) + $by;

            return $counters;
        });
    }

    public function get(string $key): int
    {
        return $this->load()[$key] ?? 0;
    }

    public function all(): array
    {
        $counters = $this->load();
        ksort($counters, \SORT_STRING);

        return $counters;
    }

    /**
     * @param callable(array<string, int>): array<string, int> $updater
     */
    private function update(callable $updater): void
    {
        self::ensureDirectory(\dirname($this->path));
        $lockPath = $this->path . '.lock';
        $lock = ($this->openLock)($lockPath);
        if (false === $lock) {
            return;
        }

        try {
            if (!$this->waitForExclusiveLock($lock)) {
                return;
            }

            $this->save($updater($this->load()));
        } finally {
            ($this->releaseLock)($lock);
            fclose($lock);
        }
    }

    /**
     * @param resource $lock
     */
    private function waitForExclusiveLock($lock): bool
    {
        $deadline = hrtime(true) + self::LOCK_WAIT_BUDGET_US * 1_000;
        while (!($this->acquireExclusiveLock)($lock)) {
            if (hrtime(true) >= $deadline) {
                return false;
            }

            usleep(self::LOCK_RETRY_SLEEP_US);
        }

        return true;
    }

    /**
     * @return array<string, int>
     */
    private function load(): array
    {
        $raw = @file_get_contents($this->path);
        if (false === $raw || '' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        // json_decode with assoc=true still returns scalars for JSON null/number/string.
        if (!\is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key) && '' !== $key && \is_int($value) && $value >= 0) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<string, int> $counters
     */
    private function save(array $counters): void
    {
        try {
            $json = json_encode($counters, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT);
        } catch (\JsonException) {
            return;
        }

        AtomicFile::write($this->path, $json . "\n");
    }

    private static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        @mkdir($directory, 0o700, true);
    }
}
