<?php

declare(strict_types=1);

namespace Tracewick\Encoder;

use Tracewick\Exception\LogicException;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\Frame;

/**
 * Language-neutral compact encoding of a profile as stack → weight for spool v2
 * ([ADR 13](../../docs/adr/0013-spool-v2-compact-payload.md)).
 *
 * Payload layout (all integers unsigned big-endian):
 * - 1 byte schema version (`1`)
 * - u32 string count; each string is u32 length + UTF-8 bytes
 * - u32 stack count; each stack is u32 weight, u8 flags (bit 0 = truncated), u32 frame count,
 *   then for each frame: u32 function index, u32 class index, u32 file index, u32 line,
 *   u32 closure line (`0xFFFFFFFF` means a null optional string or null closure line)
 */
final readonly class CompactStacksCodec
{
    public const SCHEMA_VERSION = 1;

    public const NULL_INDEX = 0xFFFFFFFF;

    /**
     * @throws LogicException when the profile cannot be encoded
     */
    public function encode(Profile $profile): string
    {
        /** @var list<string> $strings */
        $strings = [];
        /** @var array<string, int> $index */
        $index = [];
        $indexOf = static function (?string $value) use (&$strings, &$index): int {
            if (null === $value) {
                return CompactStacksCodec::NULL_INDEX;
            }

            if (isset($index[$value])) {
                return $index[$value];
            }

            $id = \count($strings);
            $strings[] = $value;
            $index[$value] = $id;

            return $id;
        };

        $frameRecords = [];
        foreach ($profile->frames as $frame) {
            $frameRecords[] = [
                $indexOf($frame->function),
                $indexOf($frame->class),
                $indexOf($frame->file),
                $frame->line,
                $frame->closureLine ?? self::NULL_INDEX,
            ];
        }

        $out = \chr(self::SCHEMA_VERSION);
        $out .= pack('N', \count($strings));
        foreach ($strings as $string) {
            $out .= pack('N', \strlen($string)) . $string;
        }

        $out .= pack('N', \count($profile->stacks));
        foreach ($profile->stacks as $stack) {
            $out .= pack('N', $stack->count);
            $out .= \chr($stack->truncated ? 1 : 0);
            $out .= pack('N', \count($stack->frameIds));
            foreach ($stack->frameIds as $frameId) {
                if (!isset($frameRecords[$frameId])) {
                    throw new LogicException(\sprintf('Stack references missing frame %d.', $frameId));
                }

                [$function, $class, $file, $line, $closureLine] = $frameRecords[$frameId];
                $out .= pack('N*', $function, $class, $file, $line, $closureLine);
            }
        }

        return $out;
    }

    /**
     * @throws LogicException when the bytes are not a valid compact-stacks payload
     */
    public function decode(string $bytes): ProfileParts
    {
        $offset = 0;
        $schema = $this->readByte($bytes, $offset);
        if (self::SCHEMA_VERSION !== $schema) {
            throw new LogicException(\sprintf('Compact stacks schema version %d is not supported.', $schema));
        }

        $stringCount = $this->readU32($bytes, $offset);
        $strings = [];
        for ($i = 0; $i < $stringCount; ++$i) {
            $length = $this->readU32($bytes, $offset);
            $strings[] = $this->readBytes($bytes, $offset, $length);
        }

        $stackCount = $this->readU32($bytes, $offset);
        $frames = [];
        $frameIds = [];
        $stacks = [];
        for ($s = 0; $s < $stackCount; ++$s) {
            $weight = $this->readU32($bytes, $offset);
            $flags = $this->readByte($bytes, $offset);
            $frameCount = $this->readU32($bytes, $offset);
            $ids = [];
            for ($f = 0; $f < $frameCount; ++$f) {
                $function = $this->optionalString($strings, $this->readU32($bytes, $offset));
                $class = $this->optionalString($strings, $this->readU32($bytes, $offset));
                $file = $this->optionalString($strings, $this->readU32($bytes, $offset));
                $line = $this->readU32($bytes, $offset);
                $closureRaw = $this->readU32($bytes, $offset);
                $frame = new Frame(
                    $function,
                    $class,
                    $file,
                    $line,
                    self::NULL_INDEX === $closureRaw ? null : $closureRaw,
                );
                $key = implode("\0", [$function ?? '', $class ?? '', $file ?? '', $line, $closureRaw]);
                if (!isset($frameIds[$key])) {
                    $frameIds[$key] = \count($frames);
                    $frames[] = $frame;
                }

                $ids[] = $frameIds[$key];
            }

            $stacks[] = new Stack($ids, $weight, 0 !== ($flags & 1));
        }

        if ($offset !== \strlen($bytes)) {
            throw new LogicException('Compact stacks payload has trailing bytes.');
        }

        return new ProfileParts($frames, $stacks);
    }

    /**
     * @param list<string> $strings
     */
    private function optionalString(array $strings, int $index): ?string
    {
        if (self::NULL_INDEX === $index) {
            return null;
        }

        if (!isset($strings[$index])) {
            throw new LogicException(\sprintf('String index %d is out of range.', $index));
        }

        return $strings[$index];
    }

    private function readByte(string $bytes, int &$offset): int
    {
        if ($offset >= \strlen($bytes)) {
            throw new LogicException('Compact stacks payload ends unexpectedly.');
        }

        return \ord($bytes[$offset++]);
    }

    private function readU32(string $bytes, int &$offset): int
    {
        $chunk = $this->readBytes($bytes, $offset, 4);
        /** @var array{1: int} $parts */
        $parts = unpack('N', $chunk);

        return $parts[1];
    }

    private function readBytes(string $bytes, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > \strlen($bytes)) {
            throw new LogicException('Compact stacks payload ends unexpectedly.');
        }

        $chunk = substr($bytes, $offset, $length);
        $offset += $length;

        return $chunk;
    }
}
