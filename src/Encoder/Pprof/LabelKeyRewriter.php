<?php

declare(strict_types=1);

namespace Tracewick\Encoder\Pprof;

/**
 * Renames the keys of sample labels in an encoded pprof profile.
 *
 * Backends have their own rules for label keys; Pyroscope, for one, drops keys that contain dots.
 * A profile that is only available as pprof bytes, such as one read from the spool, is adapted by
 * pointing each renamed key at a string appended to the string table. Every other byte, including
 * function names that happen to equal a label key, stays as it was.
 *
 * @internal
 */
final class LabelKeyRewriter
{
    // Field numbers from profile.proto.
    private const PROFILE_SAMPLE = 2;
    private const PROFILE_STRING_TABLE = 6;
    private const SAMPLE_LABEL = 3;
    private const LABEL_KEY = 1;

    /**
     * @param \Closure(string): string $rename returns the new name of a label key
     *
     * @throws ProtobufException when the bytes are not valid protocol buffers
     */
    public static function rewrite(string $pprof, \Closure $rename): string
    {
        $fields = ProtobufReader::fields($pprof);

        /** @var list<string> $strings */
        $strings = [];
        $keys = [];
        foreach ($fields as $field) {
            if (self::PROFILE_STRING_TABLE === $field['field'] && \is_string($field['value'])) {
                $strings[] = $field['value'];
            } elseif (self::PROFILE_SAMPLE === $field['field'] && \is_string($field['value'])) {
                foreach (self::labelKeys($field['value']) as $key) {
                    $keys[$key] = true;
                }
            }
        }

        /** @var array<int, int> $moved old string index => new string index */
        $moved = [];
        $added = [];
        $indexes = array_flip($strings);
        foreach (array_keys($keys) as $key) {
            // Index 0 is the empty string, which pprof uses for a missing key.
            $name = $strings[$key] ?? '';
            if ('' === $name) {
                continue;
            }

            $renamed = $rename($name);
            if ($renamed === $name) {
                continue;
            }

            if (!isset($indexes[$renamed])) {
                $indexes[$renamed] = \count($strings) + \count($added);
                $added[] = $renamed;
            }

            $moved[$key] = $indexes[$renamed];
        }

        if ([] === $moved) {
            return $pprof;
        }

        $out = '';
        foreach ($fields as $field) {
            $out .= self::PROFILE_SAMPLE === $field['field'] && \is_string($field['value'])
                ? (new ProtobufWriter())->bytes(self::PROFILE_SAMPLE, self::rewriteSample($field['value'], $moved))->toString()
                : $field['raw'];
        }

        $table = new ProtobufWriter();
        foreach ($added as $string) {
            $table->bytes(self::PROFILE_STRING_TABLE, $string);
        }

        return $out . $table->toString();
    }

    /**
     * @return list<int> string indexes of the label keys of one sample
     */
    private static function labelKeys(string $sample): array
    {
        $keys = [];
        foreach (ProtobufReader::fields($sample) as $field) {
            if (self::SAMPLE_LABEL === $field['field'] && \is_string($field['value'])) {
                $keys[] = self::labelKey($field['value']);
            }
        }

        return $keys;
    }

    private static function labelKey(string $label): int
    {
        $key = 0;
        foreach (ProtobufReader::fields($label) as $field) {
            if (self::LABEL_KEY === $field['field'] && \is_int($field['value'])) {
                $key = $field['value'];
            }
        }

        return $key;
    }

    /**
     * @param array<int, int> $moved
     */
    private static function rewriteSample(string $sample, array $moved): string
    {
        $out = '';
        foreach (ProtobufReader::fields($sample) as $field) {
            if (self::SAMPLE_LABEL !== $field['field'] || !\is_string($field['value'])) {
                $out .= $field['raw'];

                continue;
            }

            $label = '';
            foreach (ProtobufReader::fields($field['value']) as $labelField) {
                $label .= self::LABEL_KEY === $labelField['field'] && \is_int($labelField['value']) && isset($moved[$labelField['value']])
                    ? (new ProtobufWriter())->varint(self::LABEL_KEY, $moved[$labelField['value']])->toString()
                    : $labelField['raw'];
            }

            $out .= (new ProtobufWriter())->bytes(self::SAMPLE_LABEL, $label)->toString();
        }

        return $out;
    }
}
