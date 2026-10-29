<?php

declare(strict_types=1);

namespace Tracewick\Encoder\Pprof;

/**
 * Minimal protocol buffers reader that splits a message into its fields, keeping the encoded
 * bytes of each field so that a message can be rewritten without touching the fields it does
 * not change.
 *
 * @internal
 */
final class ProtobufReader
{
    public const WIRE_VARINT = 0;

    public const WIRE_FIXED64 = 1;

    public const WIRE_LENGTH_DELIMITED = 2;

    public const WIRE_FIXED32 = 5;

    /**
     * Returns the fields of a message in wire order.
     *
     * Varint values are returned as integers, length-delimited values as strings, and fixed-size
     * values as their raw bytes.
     *
     * @return list<array{field: int, wire: int, value: int|string, raw: string}>
     *
     * @throws ProtobufException when the bytes are not a valid message
     */
    public static function fields(string $message): array
    {
        $fields = [];
        $length = \strlen($message);
        $offset = 0;
        while ($offset < $length) {
            $start = $offset;
            $tag = self::varint($message, $offset);
            $field = $tag >> 3;
            if ($field < 1) {
                throw new ProtobufException(\sprintf('Invalid field number %d at byte %d.', $field, $start));
            }

            $wire = $tag & 7;
            $value = match ($wire) {
                self::WIRE_VARINT => self::varint($message, $offset),
                self::WIRE_FIXED64 => self::take($message, $offset, 8),
                self::WIRE_LENGTH_DELIMITED => self::take($message, $offset, self::varint($message, $offset)),
                self::WIRE_FIXED32 => self::take($message, $offset, 4),
                default => throw new ProtobufException(\sprintf('Unsupported wire type %d at byte %d.', $wire, $start)),
            };
            $fields[] = ['field' => $field, 'wire' => $wire, 'value' => $value, 'raw' => substr($message, $start, $offset - $start)];
        }

        return $fields;
    }

    private static function varint(string $message, int &$offset): int
    {
        $value = 0;
        for ($shift = 0; $shift < 64; $shift += 7) {
            if ($offset >= \strlen($message)) {
                throw new ProtobufException('The message ends inside a varint.');
            }

            $byte = \ord($message[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            if ($byte < 0x80) {
                return $value;
            }
        }

        throw new ProtobufException('A varint is longer than ten bytes.');
    }

    private static function take(string $message, int &$offset, int $length): string
    {
        if ($length < 0 || $length > \strlen($message) - $offset) {
            throw new ProtobufException(\sprintf('A field of %d bytes runs past the end of the message.', $length));
        }

        $value = substr($message, $offset, $length);
        $offset += $length;

        return $value;
    }
}
