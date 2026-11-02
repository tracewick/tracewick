<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

use Tracewick\Transport\AtomicFile;

/**
 * Counter store persisted as one JSON object under an exclusive lock.
 *
 * @internal prefer {@see CounterStoreFactory}
 */
final readonly class FileCounterStore implements CounterStore
{
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
            return @flock($handle, \LOCK_EX);
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
            $this->save($updater($this->load()));

            return;
        }

        try {
            if (!($this->acquireExclusiveLock)($lock)) {
                $this->save($updater($this->load()));

                return;
            }

            $this->save($updater($this->load()));
        } finally {
            ($this->releaseLock)($lock);
            fclose($lock);
        }
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
