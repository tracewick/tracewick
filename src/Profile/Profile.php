<?php

declare(strict_types=1);

namespace Tracewick\Profile;

use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\SampleBatch;

/**
 * Immutable, format-independent profile: distinct call stacks weighted by how often they were
 * sampled, over a time window.
 *
 * Frames are stored once in {@see $frames} and referenced by index from each {@see Stack}, which
 * keeps memory proportional to the number of distinct functions rather than to the number of
 * samples. Encoders turn a profile into bytes and never change it.
 */
final readonly class Profile
{
    /**
     * @param EventType   $eventType what the sampling period measures
     * @param int         $periodNs  sampling period in nanoseconds
     * @param int         $startNs   Unix time in nanoseconds at which the profile window opened
     * @param int         $endNs     Unix time in nanoseconds at which the profile window closed
     * @param list<Frame> $frames    distinct frames referenced by the stacks
     * @param list<Stack> $stacks    distinct stacks in the order they were first observed
     * @param Labels      $labels    labels describing the whole profile
     */
    public function __construct(
        public EventType $eventType,
        public int $periodNs,
        public int $startNs,
        public int $endNs,
        public array $frames = [],
        public array $stacks = [],
        public Labels $labels = new Labels(),
    ) {}

    /**
     * Aggregates the samples of a batch: identical stacks are merged and their counts summed.
     *
     * Frames are identical when every field matches, including the line being executed, so
     * that line-level formats such as pprof keep that detail. Encoders that work on function
     * names merge further.
     */
    public static function fromBatch(SampleBatch $batch, Labels $labels = new Labels()): self
    {
        /** @var array<string, int> $frameIds */
        $frameIds = [];
        $frames = [];
        /** @var array<string, array{list<int>, int, bool}> $stacks */
        $stacks = [];

        foreach ($batch->samples as $sample) {
            $ids = [];
            foreach ($sample->frames as $frame) {
                $frameKey = self::frameKey($frame);
                if (!isset($frameIds[$frameKey])) {
                    $frameIds[$frameKey] = \count($frames);
                    $frames[] = $frame;
                }

                $ids[] = $frameIds[$frameKey];
            }

            $stackKey = ($sample->truncated ? 't' : 'f') . implode(',', $ids);
            if (isset($stacks[$stackKey])) {
                $stacks[$stackKey][1] += $sample->count;
            } else {
                $stacks[$stackKey] = [$ids, $sample->count, $sample->truncated];
            }
        }

        return new self(
            $batch->eventType,
            $batch->periodNs,
            $batch->startNs,
            $batch->endNs,
            $frames,
            array_values(array_map(
                static fn(array $stack): Stack => new Stack($stack[0], $stack[1], $stack[2]),
                $stacks,
            )),
            $labels,
        );
    }

    /**
     * Returns a copy with the given labels instead of the current ones.
     */
    public function withLabels(Labels $labels): self
    {
        return new self($this->eventType, $this->periodNs, $this->startNs, $this->endNs, $this->frames, $this->stacks, $labels);
    }

    public function isEmpty(): bool
    {
        return [] === $this->stacks;
    }

    public function durationNs(): int
    {
        return max(0, $this->endNs - $this->startNs);
    }

    /**
     * Total number of sampling periods over all stacks.
     */
    public function eventCount(): int
    {
        $total = 0;
        foreach ($this->stacks as $stack) {
            $total += $stack->count;
        }

        return $total;
    }

    /**
     * Estimated time represented by the samples: the event count times the sampling period.
     */
    public function sampledNs(): int
    {
        return $this->eventCount() * $this->periodNs;
    }

    /**
     * Resolves the frames of a stack, outermost caller first.
     *
     * @return list<Frame>
     */
    public function framesOf(Stack $stack): array
    {
        $frames = [];
        foreach ($stack->frameIds as $id) {
            if (isset($this->frames[$id])) {
                $frames[] = $this->frames[$id];
            }
        }

        return $frames;
    }

    private static function frameKey(Frame $frame): string
    {
        // NUL cannot occur in PHP identifiers or paths, so the key is unambiguous.
        return implode("\0", [
            $frame->function ?? '',
            $frame->class ?? '',
            $frame->file ?? '',
            $frame->line,
            $frame->closureLine ?? '',
        ]);
    }
}
