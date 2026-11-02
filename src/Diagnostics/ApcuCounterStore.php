<?php

declare(strict_types=1);

namespace Tracewick\Diagnostics;

/**
 * Counter store backed by APCu shared memory.
 *
 * Callers must pass product-prefixed keys ({@see HostCounterKeys}); this store
 * does not add another prefix, so dumped keys match what profilers wrote.
 *
 * @internal prefer {@see CounterStoreFactory}
 */
final readonly class ApcuCounterStore implements CounterStore
{
    private const KEY_PREFIX = 'tracewick:';

    /** @var \Closure(string, int): bool */
    private \Closure $add;

    /** @var \Closure(string, int): void */
    private \Closure $inc;

    /** @var \Closure(string): mixed */
    private \Closure $fetch;

    /** @var \Closure(string): iterable<mixed> */
    private \Closure $iterate;

    /**
     * @param ?\Closure(string, int): bool       $add
     * @param ?\Closure(string, int): void       $inc
     * @param ?\Closure(string): mixed           $fetch
     * @param ?\Closure(string): iterable<mixed> $iterate
     *
     * @internal callables exist so tests cover the store without ext-apcu
     */
    public function __construct(
        ?\Closure $add = null,
        ?\Closure $inc = null,
        ?\Closure $fetch = null,
        ?\Closure $iterate = null,
    ) {
        $this->add = $add ?? (static fn(string $key, int $value): bool => \function_exists('apcu_add') && apcu_add($key, $value));
        $this->inc = $inc ?? static function (string $key, int $by): void {
            if (\function_exists('apcu_inc')) {
                apcu_inc($key, $by);
            }
        };
        $this->fetch = $fetch ?? (static fn(string $key): mixed => \function_exists('apcu_fetch') ? apcu_fetch($key) : false);
        $this->iterate = $iterate ?? static function (string $prefix): iterable {
            if (!class_exists(\APCUIterator::class, false)) {
                return [];
            }

            return new \APCUIterator('/^' . preg_quote($prefix, '/') . '/');
        };
    }

    public function increment(string $key, int $by = 1): void
    {
        if ($by < 1 || '' === $key) {
            return;
        }

        if (!($this->add)($key, $by)) {
            ($this->inc)($key, $by);
        }
    }

    public function get(string $key): int
    {
        $value = ($this->fetch)($key);

        return \is_int($value) ? max(0, $value) : 0;
    }

    public function all(): array
    {
        $out = [];
        foreach (($this->iterate)(self::KEY_PREFIX) as $entry) {
            if (
                !\is_array($entry)
                || !isset($entry['key'], $entry['value'])
                || !\is_string($entry['key'])
                || !str_starts_with($entry['key'], self::KEY_PREFIX)
                || !\is_int($entry['value'])
            ) {
                continue;
            }

            $out[$entry['key']] = max(0, $entry['value']);
        }

        ksort($out, \SORT_STRING);

        return $out;
    }
}
