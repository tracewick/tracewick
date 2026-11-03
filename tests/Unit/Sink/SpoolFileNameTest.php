<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tracewick\Sink\SpoolFileName;

#[CoversClass(SpoolFileName::class)]
final class SpoolFileNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function names(): iterable
    {
        yield 'with hash' => ['01798000000000000001-abcdef0123456789-0000000000000001.spool', 'abcdef0123456789'];
        yield 'with shard path' => ['ab/01798000000000000001-abcdef0123456789-0000000000000001.spool', 'abcdef0123456789'];
        yield 'without hash' => ['01798000000000000001-0000000000000001.spool', null];
        yield 'other' => ['notes.txt', null];
    }

    #[DataProvider('names')]
    public function testReadsTheAggregationKeyHash(string $name, ?string $expected): void
    {
        self::assertSame($expected, SpoolFileName::aggregationKeyHash($name));
    }
}
