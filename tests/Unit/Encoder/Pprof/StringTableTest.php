<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Encoder\Pprof;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\Pprof\StringTable;

#[CoversClass(StringTable::class)]
final class StringTableTest extends TestCase
{
    public function testStartsWithTheEmptyString(): void
    {
        self::assertSame([''], (new StringTable())->all());
    }

    public function testStoresEachStringOnce(): void
    {
        $strings = new StringTable();

        self::assertSame(0, $strings->index(''));
        self::assertSame(1, $strings->index('samples'));
        self::assertSame(2, $strings->index('count'));
        self::assertSame(1, $strings->index('samples'));
        self::assertSame(3, $strings->index('0'));
        self::assertSame(['', 'samples', 'count', '0'], $strings->all());
    }
}
