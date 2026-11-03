<?php

declare(strict_types=1);

namespace Tracewick\Encoder;

use Tracewick\Profile\Profile;

/**
 * Encodes a profile in the JSON file format of speedscope (https://www.speedscope.app), as one
 * sampled profile with weights in nanoseconds.
 *
 * Frames that differ only by the line being executed become one speedscope frame, because
 * speedscope identifies a frame by its name and file. Output is deterministic: frames and samples
 * follow the order of the profile.
 *
 * @see https://github.com/jlfwong/speedscope/wiki/Importing-from-custom-sources
 */
final readonly class SpeedscopeEncoder implements Encoder
{
    public const SCHEMA = 'https://www.speedscope.app/file-format-schema.json';

    private const JSON_FLAGS = \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        | \JSON_INVALID_UTF8_SUBSTITUTE;

    public function encode(Profile $profile): string
    {
        /** @var array<string, int> $indexByKey */
        $indexByKey = [];
        /** @var list<array{name: string, file?: string}> $frames */
        $frames = [];
        $index = static function (string $name, ?string $file) use (&$indexByKey, &$frames): int {
            $key = $name . "\0" . $file;
            if (!isset($indexByKey[$key])) {
                $indexByKey[$key] = \count($frames);
                // Internal functions have no file; speedscope then shows the name only.
                $frames[] = null === $file ? ['name' => $name] : ['name' => $name, 'file' => $file];
            }

            return $indexByKey[$key];
        };

        $samples = [];
        $weights = [];
        foreach ($profile->stacks as $stack) {
            $sample = [];
            if ($stack->truncated) {
                $sample[] = $index(CollapsedEncoder::TRUNCATED_FRAME, null);
            }

            foreach ($profile->framesOf($stack) as $frame) {
                $sample[] = $index($frame->name(), $frame->file);
            }

            if ([] === $sample) {
                $sample[] = $index(CollapsedEncoder::UNKNOWN_FRAME, null);
            }

            $samples[] = $sample;
            $weights[] = $stack->count * $profile->periodNs;
        }

        return json_encode([
            '$schema' => self::SCHEMA,
            'shared' => ['frames' => $frames],
            'profiles' => [[
                'type' => 'sampled',
                'name' => $this->profileName($profile),
                'unit' => 'nanoseconds',
                'startValue' => 0,
                'endValue' => array_sum($weights),
                'samples' => $samples,
                'weights' => $weights,
            ]],
            'name' => $this->profileName($profile),
            'activeProfileIndex' => 0,
            'exporter' => 'tracewick',
        ], self::JSON_FLAGS);
    }

    public function mediaType(): string
    {
        return 'application/json';
    }

    /**
     * For example `wall` or `cpu env=prod service=shop`.
     */
    private function profileName(Profile $profile): string
    {
        $name = $profile->eventType->value;
        foreach ($profile->labels as $key => $value) {
            $name .= ' ' . $key . '=' . $value;
        }

        return $name;
    }
}
