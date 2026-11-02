<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Diagnostics\ApcuCounterStore;

#[CoversClass(ApcuCounterStore::class)]
final class ApcuCounterStoreTest extends TestCase
{
    public function testIncrementsViaAddThenInc(): void
    {
        /** @var array<string, int> $memory */
        $memory = [];
        $store = new ApcuCounterStore(
            static function (string $key, int $value) use (&$memory): bool {
                if (isset($memory[$key])) {
                    return false;
                }

                $memory[$key] = $value;

                return true;
            },
            static function (string $key, int $by) use (&$memory): void {
                $memory[$key] = ($memory[$key] ?? 0) + $by;
            },
            static function (string $key) use (&$memory): mixed {
                return $memory[$key] ?? false;
            },
            static function (string $prefix) use (&$memory): iterable {
                foreach ($memory as $key => $value) {
                    if (str_starts_with($key, $prefix)) {
                        yield ['key' => $key, 'value' => $value];
                    }
                }
            },
        );

        $store->increment('tracewick:a', 2);
        $store->increment('tracewick:a');
        $store->increment('', 5);
        $store->increment('tracewick:b', 0);

        self::assertSame(3, $store->get('tracewick:a'));
        self::assertSame(0, $store->get('missing'));
        self::assertSame(['tracewick:a' => 3], $store->all());
    }

    public function testAllSkipsMalformedIteratorEntriesAndForeignKeys(): void
    {
        $store = new ApcuCounterStore(
            static fn(string $key, int $value): bool => true,
            static function (string $key, int $by): void {},
            static fn(string $key): mixed => 'x',
            static function (string $prefix): iterable {
                yield 'not-an-array';
                yield ['key' => 1, 'value' => 2];
                yield ['key' => 'tracewick:ok', 'value' => 4];
                yield ['key' => 'other:lib', 'value' => 9];
                yield ['key' => 'tracewick:bad', 'value' => 'nope'];
            },
        );

        self::assertSame(0, $store->get('tracewick:ok'));
        self::assertSame(['tracewick:ok' => 4], $store->all());
    }

    public function testAllIsFailOpenWhenTheIteratorThrows(): void
    {
        $store = new ApcuCounterStore(
            static fn(string $key, int $value): bool => false,
            static function (string $key, int $by): void {},
            static fn(string $key): mixed => false,
            static function (string $prefix): iterable {
                throw new \Error('APC must be enabled to use APCUIterator');
            },
        );

        self::assertSame([], $store->all());
    }

    public function testDefaultBindingsAreFailOpenWhenApcuIsUnavailable(): void
    {
        if (\function_exists('apcu_enabled') && apcu_enabled()) {
            self::markTestSkipped('APCu is enabled in this process.');
        }

        // CI often loads ext-apcu with apc.enable_cli=0; constructing APCUIterator then throws.
        $store = new ApcuCounterStore();
        $store->increment('tracewick:a');
        self::assertSame(0, $store->get('tracewick:a'));
        self::assertSame([], $store->all());
    }

    public function testWorksAgainstRealApcuWhenEnabled(): void
    {
        if (!\function_exists('apcu_enabled') || !apcu_enabled()) {
            self::markTestSkipped('ext-apcu is not enabled.');
        }

        $key = 'tracewick:real-' . bin2hex(random_bytes(4));
        $store = new ApcuCounterStore();
        $store->increment($key, 2);
        $store->increment($key);
        self::assertSame(3, $store->get($key));
        self::assertArrayHasKey($key, $store->all());
    }
}
