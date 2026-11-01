<?php

declare(strict_types=1);

namespace Tracewick\Encoder;

use Tracewick\Encoder\Pprof\ProtobufWriter;
use Tracewick\Encoder\Pprof\StringTable;
use Tracewick\Profile\Profile;
use Tracewick\Sampler\Frame;

/**
 * Encodes a profile in the pprof format (profile.proto), the primary interchange format
 * (ADR 4) used with Grafana Pyroscope and validated with `go tool pprof`.
 *
 * The output is uncompressed protobuf. Callers may gzip when a receiver requires it; this
 * encoder and the built-in HTTP transport do not compress. Each sample carries two values, the
 * sample count and the time it represents in nanoseconds, and the profile labels as string
 * labels. Every distinct frame (function and line) becomes a location, and frames of the same
 * function and file share one function entry. Output is deterministic: tables follow the order
 * of the profile.
 *
 * @see https://github.com/google/pprof/blob/main/proto/profile.proto
 */
final readonly class PprofEncoder implements Encoder
{
    // Field numbers from profile.proto.
    private const PROFILE_SAMPLE_TYPE = 1;
    private const PROFILE_SAMPLE = 2;
    private const PROFILE_LOCATION = 4;
    private const PROFILE_FUNCTION = 5;
    private const PROFILE_STRING_TABLE = 6;
    private const PROFILE_TIME_NANOS = 9;
    private const PROFILE_DURATION_NANOS = 10;
    private const PROFILE_PERIOD_TYPE = 11;
    private const PROFILE_PERIOD = 12;
    private const VALUE_TYPE_TYPE = 1;
    private const VALUE_TYPE_UNIT = 2;
    private const SAMPLE_LOCATION_ID = 1;
    private const SAMPLE_VALUE = 2;
    private const SAMPLE_LABEL = 3;
    private const LABEL_KEY = 1;
    private const LABEL_STR = 2;
    private const LOCATION_ID = 1;
    private const LOCATION_LINE = 4;
    private const LINE_FUNCTION_ID = 1;
    private const LINE_LINE = 2;
    private const FUNCTION_ID = 1;
    private const FUNCTION_NAME = 2;
    private const FUNCTION_SYSTEM_NAME = 3;
    private const FUNCTION_FILENAME = 4;
    private const FUNCTION_START_LINE = 5;

    public function encode(Profile $profile): string
    {
        $strings = new StringTable();
        $out = new ProtobufWriter();

        $out->bytes(self::PROFILE_SAMPLE_TYPE, $this->valueType($strings, 'samples', 'count'));
        $out->bytes(self::PROFILE_SAMPLE_TYPE, $this->valueType($strings, $profile->eventType->value, 'nanoseconds'));

        /** @var array<string, int> $functionIds */
        $functionIds = [];
        $functions = new ProtobufWriter();
        /** @var array<int|string, int> $locationIds */
        $locationIds = [];
        $locations = new ProtobufWriter();

        $function = static function (string $name, ?string $file, int $startLine) use (&$functionIds, $functions, $strings): int {
            $key = $name . "\0" . $file;
            if (!isset($functionIds[$key])) {
                $id = $functionIds[$key] = \count($functionIds) + 1;
                $functions->bytes(self::PROFILE_FUNCTION, (new ProtobufWriter())
                    ->varint(self::FUNCTION_ID, $id)
                    ->varint(self::FUNCTION_NAME, $strings->index($name))
                    ->varint(self::FUNCTION_SYSTEM_NAME, $strings->index($name))
                    ->varint(self::FUNCTION_FILENAME, $strings->index($file ?? ''))
                    ->varint(self::FUNCTION_START_LINE, $startLine)
                    ->toString());
            }

            return $functionIds[$key];
        };

        $location = static function (int|string $key, int $functionId, int $line) use (&$locationIds, $locations): int {
            if (!isset($locationIds[$key])) {
                $id = $locationIds[$key] = \count($locationIds) + 1;
                $locations->bytes(self::PROFILE_LOCATION, (new ProtobufWriter())
                    ->varint(self::LOCATION_ID, $id)
                    ->bytes(self::LOCATION_LINE, (new ProtobufWriter())
                        ->varint(self::LINE_FUNCTION_ID, $functionId)
                        ->varint(self::LINE_LINE, $line)
                        ->toString())
                    ->toString());
            }

            return $locationIds[$key];
        };

        $samples = new ProtobufWriter();
        foreach ($profile->stacks as $stack) {
            $ids = [];
            foreach ($stack->frameIds as $frameId) {
                $frame = $profile->frames[$frameId] ?? null;
                $ids[] = null === $frame
                    ? $location(CollapsedEncoder::UNKNOWN_FRAME, $function(CollapsedEncoder::UNKNOWN_FRAME, null, 0), 0)
                    : $location($frameId, $function($frame->name(), $frame->file, $this->startLine($frame)), $frame->line);
            }

            if ($stack->truncated) {
                array_unshift($ids, $location(CollapsedEncoder::TRUNCATED_FRAME, $function(CollapsedEncoder::TRUNCATED_FRAME, null, 0), 0));
            }

            if ([] === $ids) {
                $ids[] = $location(CollapsedEncoder::UNKNOWN_FRAME, $function(CollapsedEncoder::UNKNOWN_FRAME, null, 0), 0);
            }

            $sample = (new ProtobufWriter())
                // pprof lists locations from the innermost frame outwards.
                ->packed(self::SAMPLE_LOCATION_ID, array_reverse($ids))
                ->packed(self::SAMPLE_VALUE, [$stack->count, $stack->count * $profile->periodNs]);
            foreach ($profile->labels as $key => $value) {
                $sample->bytes(self::SAMPLE_LABEL, (new ProtobufWriter())
                    ->varint(self::LABEL_KEY, $strings->index($key))
                    ->varint(self::LABEL_STR, $strings->index($value))
                    ->toString());
            }

            $samples->bytes(self::PROFILE_SAMPLE, $sample->toString());
        }

        // The string table is complete only now, so it and the fields after it come last.
        $tail = new ProtobufWriter();
        $periodType = $this->valueType($strings, $profile->eventType->value, 'nanoseconds');
        foreach ($strings->all() as $string) {
            $tail->bytes(self::PROFILE_STRING_TABLE, $string);
        }

        $tail->varint(self::PROFILE_TIME_NANOS, $profile->startNs)
            ->varint(self::PROFILE_DURATION_NANOS, $profile->durationNs())
            ->bytes(self::PROFILE_PERIOD_TYPE, $periodType)
            ->varint(self::PROFILE_PERIOD, $profile->periodNs);

        return $out->toString() . $samples->toString() . $locations->toString() . $functions->toString()
            . $tail->toString();
    }

    public function mediaType(): string
    {
        return 'application/x-protobuf';
    }

    private function valueType(StringTable $strings, string $type, string $unit): string
    {
        return (new ProtobufWriter())
            ->varint(self::VALUE_TYPE_TYPE, $strings->index($type))
            ->varint(self::VALUE_TYPE_UNIT, $strings->index($unit))
            ->toString();
    }

    /**
     * pprof's start_line is the first line of the function; it is known only for closures.
     */
    private function startLine(Frame $frame): int
    {
        return $frame->closureLine ?? 0;
    }
}
