<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Decodes protocol buffers well enough to inspect what the pprof encoder wrote. Fields are
 * read by number, as profile.proto defines them; absent fields read as the proto3 default.
 */
final readonly class ProtobufMessage
{
    /**
     * @param array<int, list<int|string>> $fields values of each field, in wire order
     */
    private function __construct(private array $fields) {}

    public static function decode(string $message): self
    {
        $fields = [];
        $offset = 0;
        while ($offset < \strlen($message)) {
            $tag = self::readVarint($message, $offset);
            $fields[$tag >> 3][] = match ($tag & 7) {
                0 => self::readVarint($message, $offset),
                2 => self::readBytes($message, $offset),
                default => Assert::fail(\sprintf('Unexpected wire type %d.', $tag & 7)),
            };
        }

        return new self($fields);
    }

    public function has(int $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function int(int $field): int
    {
        $value = $this->last($field) ?? 0;
        Assert::assertIsInt($value);

        return $value;
    }

    public function string(int $field): string
    {
        $value = $this->last($field) ?? '';
        Assert::assertIsString($value);

        return $value;
    }

    /**
     * @return list<string>
     */
    public function strings(int $field): array
    {
        $values = $this->fields[$field] ?? [];
        Assert::assertContainsOnlyString($values);

        return $values;
    }

    /**
     * @return list<self>
     */
    public function messages(int $field): array
    {
        return array_map(self::decode(...), $this->strings($field));
    }

    public function message(int $field): self
    {
        return self::decode($this->string($field));
    }

    /**
     * Reads a packed repeated varint field.
     *
     * @return list<int>
     */
    public function packed(int $field): array
    {
        $payload = $this->string($field);
        $values = [];
        $offset = 0;
        while ($offset < \strlen($payload)) {
            $values[] = self::readVarint($payload, $offset);
        }

        return $values;
    }

    private function last(int $field): int|string|null
    {
        $values = $this->fields[$field] ?? [];

        return [] === $values ? null : $values[\count($values) - 1];
    }

    /**
     * Reads a base 128 varint at the offset and advances the offset past it.
     */
    public static function readVarint(string $buffer, int &$offset): int
    {
        $value = 0;
        $shift = 0;
        do {
            Assert::assertLessThan(\strlen($buffer), $offset, 'Truncated varint.');
            $byte = \ord($buffer[$offset++]);
            $value |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (0 !== ($byte & 0x80));

        return $value;
    }

    /**
     * Reads a length-delimited value at the offset and advances the offset past it.
     */
    public static function readBytes(string $buffer, int &$offset): string
    {
        $length = self::readVarint($buffer, $offset);
        Assert::assertLessThanOrEqual(\strlen($buffer), $offset + $length, 'Truncated field.');
        $value = substr($buffer, $offset, $length);
        $offset += $length;

        return $value;
    }
}
