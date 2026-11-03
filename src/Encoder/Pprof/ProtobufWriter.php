<?php

declare(strict_types=1);

namespace Tracewick\Encoder\Pprof;

/**
 * Minimal protocol buffers writer for the field types that profile.proto uses: varints,
 * length-delimited bytes and embedded messages, and packed repeated varints.
 *
 * Fields holding the proto3 default (zero or empty) are skipped, as protobuf serializers do.
 *
 * @internal
 */
final class ProtobufWriter
{
    private const WIRE_VARINT = 0;

    private const WIRE_LENGTH_DELIMITED = 2;

    private string $buffer = '';

    /**
     * Writes an int64, uint64 or bool field.
     */
    public function varint(int $field, int $value): self
    {
        if (0 !== $value) {
            $this->buffer .= self::encodeVarint($field << 3 | self::WIRE_VARINT) . self::encodeVarint($value);
        }

        return $this;
    }

    /**
     * Writes a string, bytes or embedded message field. Empty values are written too, because
     * an empty element of a repeated field still counts.
     */
    public function bytes(int $field, string $value): self
    {
        $this->buffer .= self::encodeVarint($field << 3 | self::WIRE_LENGTH_DELIMITED)
            . self::encodeVarint(\strlen($value)) . $value;

        return $this;
    }

    /**
     * Writes a packed repeated int64 or uint64 field.
     *
     * @param list<int> $values
     */
    public function packed(int $field, array $values): self
    {
        if ([] === $values) {
            return $this;
        }

        $payload = '';
        foreach ($values as $value) {
            $payload .= self::encodeVarint($value);
        }

        return $this->bytes($field, $payload);
    }

    public function toString(): string
    {
        return $this->buffer;
    }

    /**
     * Base 128 varint. Negative numbers take ten bytes, as int64 two's complement.
     */
    public static function encodeVarint(int $value): string
    {
        $bytes = '';
        while ($value < 0 || $value > 0x7F) {
            $bytes .= \chr($value & 0x7F | 0x80);
            // Logical shift: PHP's >> keeps the sign, which would never terminate for negatives.
            $value = $value >> 7 & 0x01FF_FFFF_FFFF_FFFF;
        }

        return $bytes . \chr($value);
    }
}
