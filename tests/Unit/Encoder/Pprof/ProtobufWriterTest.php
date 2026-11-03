<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Encoder\Pprof;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\Pprof\ProtobufWriter;

#[CoversClass(ProtobufWriter::class)]
final class ProtobufWriterTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function varints(): iterable
    {
        yield 'zero' => [0, "\x00"];
        yield 'one' => [1, "\x01"];
        yield 'largest single byte' => [127, "\x7F"];
        yield 'smallest two bytes' => [128, "\x80\x01"];
        yield 'protobuf docs example' => [150, "\x96\x01"];
        yield 'int max' => [\PHP_INT_MAX, str_repeat("\xFF", 8) . "\x7F"];
        yield 'minus one' => [-1, str_repeat("\xFF", 9) . "\x01"];
        yield 'int min' => [\PHP_INT_MIN, str_repeat("\x80", 9) . "\x01"];
    }

    #[DataProvider('varints')]
    public function testEncodesVarints(int $value, string $expected): void
    {
        self::assertSame(bin2hex($expected), bin2hex(ProtobufWriter::encodeVarint($value)));
    }

    public function testWritesVarintFields(): void
    {
        $writer = (new ProtobufWriter())->varint(1, 150)->varint(16, 1);

        // Field 16 needs a two-byte tag.
        self::assertSame('089601' . '800101', bin2hex($writer->toString()));
    }

    public function testSkipsZeroVarints(): void
    {
        self::assertSame('', (new ProtobufWriter())->varint(1, 0)->toString());
    }

    public function testWritesNegativeVarintsInTenBytes(): void
    {
        self::assertSame('08' . str_repeat('ff', 9) . '01', bin2hex((new ProtobufWriter())->varint(1, -1)->toString()));
    }

    public function testWritesLengthDelimitedFields(): void
    {
        $writer = (new ProtobufWriter())->bytes(2, 'testing')->bytes(6, '');

        self::assertSame('1207' . bin2hex('testing') . '3200', bin2hex($writer->toString()));
    }

    public function testWritesPackedFields(): void
    {
        $writer = (new ProtobufWriter())->packed(4, [3, 270, 86942]);

        self::assertSame('2206038e029ea705', bin2hex($writer->toString()));
    }

    public function testSkipsEmptyPackedFields(): void
    {
        self::assertSame('', (new ProtobufWriter())->packed(4, [])->toString());
    }

    public function testStartsEmpty(): void
    {
        self::assertSame('', (new ProtobufWriter())->toString());
    }
}
