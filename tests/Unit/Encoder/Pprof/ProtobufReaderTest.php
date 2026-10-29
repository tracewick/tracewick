<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Encoder\Pprof;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\Pprof\ProtobufException;
use Tracewick\Encoder\Pprof\ProtobufReader;
use Tracewick\Encoder\Pprof\ProtobufWriter;

#[CoversClass(ProtobufReader::class)]
#[UsesClass(ProtobufWriter::class)]
#[UsesClass(ProtobufException::class)]
final class ProtobufReaderTest extends TestCase
{
    public function testReadsEveryWireType(): void
    {
        $message = (new ProtobufWriter())->varint(1, 150)->bytes(2, 'testing')->toString()
            . "\x19" . "\x01\x02\x03\x04\x05\x06\x07\x08"
            . "\x25" . "\x0a\x0b\x0c\x0d";

        self::assertSame([
            ['field' => 1, 'wire' => 0, 'value' => 150, 'raw' => "\x08\x96\x01"],
            ['field' => 2, 'wire' => 2, 'value' => 'testing', 'raw' => "\x12\x07testing"],
            ['field' => 3, 'wire' => 1, 'value' => "\x01\x02\x03\x04\x05\x06\x07\x08", 'raw' => "\x19\x01\x02\x03\x04\x05\x06\x07\x08"],
            ['field' => 4, 'wire' => 5, 'value' => "\x0a\x0b\x0c\x0d", 'raw' => "\x25\x0a\x0b\x0c\x0d"],
        ], ProtobufReader::fields($message));
    }

    public function testReadsAnEmptyMessage(): void
    {
        self::assertSame([], ProtobufReader::fields(''));
    }

    public function testReadsLargeFieldNumbersAndValues(): void
    {
        $message = (new ProtobufWriter())->varint(536_870_911, \PHP_INT_MAX)->varint(5, -1)->bytes(6, '')->toString();

        $fields = ProtobufReader::fields($message);

        self::assertSame([536_870_911, \PHP_INT_MAX], [$fields[0]['field'], $fields[0]['value']]);
        self::assertSame(-1, $fields[1]['value']);
        self::assertSame('', $fields[2]['value']);
        self::assertSame($message, implode('', array_column($fields, 'raw')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'field number zero' => ["\x00\x01", 'Invalid field number 0 at byte 0.'];
        yield 'group start' => ["\x0b", 'Unsupported wire type 3 at byte 0.'];
        yield 'group end' => ["\x08\x01\x0c", 'Unsupported wire type 4 at byte 2.'];
        yield 'truncated tag' => ["\x80", 'The message ends inside a varint.'];
        yield 'truncated varint' => ["\x08\x96", 'The message ends inside a varint.'];
        yield 'varint of eleven bytes' => ["\x08" . str_repeat("\xff", 10) . "\x01", 'A varint is longer than ten bytes.'];
        yield 'bytes past the end' => ["\x12\x05abc", 'A field of 5 bytes runs past the end of the message.'];
        yield 'negative length' => ["\x12" . str_repeat("\xff", 9) . "\x01", 'A field of -1 bytes runs past the end of the message.'];
        yield 'truncated fixed64' => ["\x19\x01\x02", 'A field of 8 bytes runs past the end of the message.'];
        yield 'truncated fixed32' => ["\x25\x01", 'A field of 4 bytes runs past the end of the message.'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidMessages(string $message, string $error): void
    {
        $this->expectException(ProtobufException::class);
        $this->expectExceptionMessage($error);

        ProtobufReader::fields($message);
    }
}
