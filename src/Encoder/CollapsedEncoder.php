<?php

declare(strict_types=1);

namespace Tracewick\Encoder;

use Tracewick\Profile\Profile;

/**
 * Encodes a profile in the collapsed ("folded") stack format of Brendan Gregg's FlameGraph tools,
 * which flamegraph.pl, speedscope, inferno and Pyroscope ingest.
 *
 * Each line holds the frame names of one stack, outermost first and separated by semicolons,
 * then a space and a number. Stacks are merged by function name, ignoring the line being
 * executed, and lines are sorted so that equal profiles always produce identical output.
 */
final readonly class CollapsedEncoder implements Encoder
{
    /**
     * Root frame added to stacks that hit the sampler depth limit, so that the missing callers
     * show up as one visible block instead of being spread over the real roots.
     */
    public const TRUNCATED_FRAME = '[truncated]';

    /**
     * Frame used for samples that carry no frames at all.
     */
    public const UNKNOWN_FRAME = '[unknown]';

    public function __construct(
        private CollapsedValue $value = CollapsedValue::Samples,
    ) {}

    public function encode(Profile $profile): string
    {
        $names = [];
        foreach ($profile->frames as $id => $frame) {
            $names[$id] = $this->sanitize($frame->name());
        }

        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($profile->stacks as $stack) {
            $path = [];
            if ($stack->truncated) {
                $path[] = self::TRUNCATED_FRAME;
            }

            foreach ($stack->frameIds as $id) {
                $path[] = $names[$id] ?? self::UNKNOWN_FRAME;
            }

            if ([] === $path) {
                $path[] = self::UNKNOWN_FRAME;
            }

            $key = implode(';', $path);
            $counts[$key] = ($counts[$key] ?? 0) + $stack->count;
        }

        ksort($counts, \SORT_STRING);

        $multiplier = CollapsedValue::Nanoseconds === $this->value ? $profile->periodNs : 1;
        $output = '';
        foreach ($counts as $path => $count) {
            $output .= $path . ' ' . ($count * $multiplier) . "\n";
        }

        return $output;
    }

    public function mediaType(): string
    {
        return 'text/plain; charset=utf-8';
    }

    /**
     * Keeps a name from breaking the line format: semicolons separate frames and line breaks
     * separate stacks. Spaces are allowed because only the last one precedes the number.
     */
    private function sanitize(string $name): string
    {
        return strtr($name, [';' => ':', "\n" => ' ', "\r" => ' ']);
    }
}
