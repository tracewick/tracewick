<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Diagnostics\ArrayCounterStore;
use Tracewick\Diagnostics\NullCounterStore;

#[CoversClass(ArrayCounterStore::class)]
#[CoversClass(NullCounterStore::class)]
final class ArrayCounterStoreTest extends TestCase
{
    public function testArrayStoreAccumulates(): void
    {
        $store = new ArrayCounterStore();
        $store->increment('a', 2);
        $store->increment('a');
        $store->increment('a', 0);
        $store->increment('');
        self::assertSame(3, $store->get('a'));
        self::assertSame(['a' => 3], $store->all());
    }

    public function testNullStoreIsNoOp(): void
    {
        $store = new NullCounterStore();
        $store->increment('a', 5);
        self::assertSame(0, $store->get('a'));
        self::assertSame([], $store->all());
    }
}
