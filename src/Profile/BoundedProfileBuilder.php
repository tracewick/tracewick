<?php

declare(strict_types=1);

namespace Tracewick\Profile;

use Tracewick\Exception\LogicException;
use Tracewick\Sampler\Frame;

/**
 * Builds a bounded set of frames and weighted stacks with transactional admission.
 *
 * Lookup never mutates tables. Cost is checked next. Overflow adds weight without interning.
 * Otherwise the new frames and stack are committed. Invariant: {@see inWeight()} equals
 * {@see keptWeight()} + {@see overflowWeight()}.
 *
 * @internal
 */
final class BoundedProfileBuilder
{
    public const OVERFLOW_FUNCTION = '[overflow]';

    public const DEFAULT_MAX_STACKS = 4_096;

    public const DEFAULT_MAX_FRAMES = 16_384;

    public const DEFAULT_MAX_STRINGS = 32_768;

    public const MIN_CAP = 1;

    public const MAX_STACKS = 100_000;

    public const MAX_FRAMES = 500_000;

    public const MAX_STRINGS = 1_000_000;

    /** @var array<string, int> */
    private array $frameIds = [];

    /** @var list<Frame> */
    private array $frames = [];

    /** @var array<string, true> */
    private array $strings = [];

    /** @var array<string, array{list<int>, int, bool}> */
    private array $stacks = [];

    private int $inWeight = 0;

    private int $keptWeight = 0;

    private int $overflowWeight = 0;

    private bool $capped = false;

    public function __construct(
        private readonly int $maxStacks = self::DEFAULT_MAX_STACKS,
        private readonly int $maxFrames = self::DEFAULT_MAX_FRAMES,
        private readonly int $maxStrings = self::DEFAULT_MAX_STRINGS,
    ) {
        if ($maxStacks < self::MIN_CAP || $maxStacks > self::MAX_STACKS) {
            throw new LogicException(\sprintf(
                'Maximum unique stacks must be between %d and %d, got %d.',
                self::MIN_CAP,
                self::MAX_STACKS,
                $maxStacks,
            ));
        }

        if ($maxFrames < self::MIN_CAP || $maxFrames > self::MAX_FRAMES) {
            throw new LogicException(\sprintf(
                'Maximum unique frames must be between %d and %d, got %d.',
                self::MIN_CAP,
                self::MAX_FRAMES,
                $maxFrames,
            ));
        }

        if ($maxStrings < self::MIN_CAP || $maxStrings > self::MAX_STRINGS) {
            throw new LogicException(\sprintf(
                'Maximum unique strings must be between %d and %d, got %d.',
                self::MIN_CAP,
                self::MAX_STRINGS,
                $maxStrings,
            ));
        }
    }

    /**
     * @param list<Frame> $frames outermost caller first
     */
    public function add(array $frames, int $weight, bool $truncated = false): void
    {
        if ($weight < 1) {
            return;
        }

        $this->inWeight += $weight;

        /** @var array<string, array{id: int, frame: Frame}> $pendingFrames */
        $pendingFrames = [];
        /** @var array<string, true> $pendingStrings */
        $pendingStrings = [];
        $ids = [];
        $nextId = \count($this->frames);

        foreach ($frames as $frame) {
            $frameKey = $this->frameKey($frame);
            if (isset($this->frameIds[$frameKey])) {
                $ids[] = $this->frameIds[$frameKey];

                continue;
            }

            if (isset($pendingFrames[$frameKey])) {
                $ids[] = $pendingFrames[$frameKey]['id'];

                continue;
            }

            $pendingFrames[$frameKey] = ['id' => $nextId, 'frame' => $frame];
            $ids[] = $nextId;
            ++$nextId;

            foreach ([$frame->function, $frame->class, $frame->file] as $string) {
                if (null === $string || '' === $string) {
                    continue;
                }

                if (isset($this->strings[$string]) || isset($pendingStrings[$string])) {
                    continue;
                }

                $pendingStrings[$string] = true;
            }
        }

        $stackKey = implode(',', $ids);
        if ($truncated) {
            $stackKey .= ',truncated';
        }

        if (isset($this->stacks[$stackKey])) {
            $this->stacks[$stackKey][1] += $weight;
            $this->keptWeight += $weight;

            return;
        }

        if (
            \count($this->stacks) + 1 > $this->maxStacks
            || \count($this->frames) + \count($pendingFrames) > $this->maxFrames
            || \count($this->strings) + \count($pendingStrings) > $this->maxStrings
        ) {
            $this->capped = true;
            $this->overflowWeight += $weight;

            return;
        }

        foreach ($pendingFrames as $frameKey => $pending) {
            $this->frameIds[$frameKey] = \count($this->frames);
            $this->frames[] = $pending['frame'];
        }

        foreach ($pendingStrings as $string => $_) {
            $this->strings[$string] = true;
        }

        $this->stacks[$stackKey] = [$ids, $weight, $truncated];
        $this->keptWeight += $weight;
    }

    public function inWeight(): int
    {
        return $this->inWeight;
    }

    public function keptWeight(): int
    {
        return $this->keptWeight;
    }

    public function overflowWeight(): int
    {
        return $this->overflowWeight;
    }

    public function wasCapped(): bool
    {
        return $this->capped;
    }

    public function isEmpty(): bool
    {
        return [] === $this->stacks && 0 === $this->overflowWeight;
    }

    public function uniqueStackCount(): int
    {
        return \count($this->stacks) + ($this->overflowWeight > 0 ? 1 : 0);
    }

    public function frameCount(): int
    {
        return \count($this->frames);
    }

    public function stringCount(): int
    {
        return \count($this->strings);
    }

    /**
     * Frames and stacks ready for a {@see Profile}, including an overflow frame when needed.
     *
     * @return array{0: list<Frame>, 1: list<Stack>}
     */
    public function toProfileParts(): array
    {
        $frames = $this->frames;
        $stacks = [];
        foreach ($this->stacks as [$ids, $count, $truncated]) {
            $stacks[] = new Stack($ids, $count, $truncated);
        }

        if ($this->overflowWeight > 0) {
            $overflowId = \count($frames);
            $frames[] = new Frame(self::OVERFLOW_FUNCTION);
            $stacks[] = new Stack([$overflowId], $this->overflowWeight, true);
        }

        return [$frames, $stacks];
    }

    public function reset(): void
    {
        $this->frameIds = [];
        $this->frames = [];
        $this->strings = [];
        $this->stacks = [];
        $this->inWeight = 0;
        $this->keptWeight = 0;
        $this->overflowWeight = 0;
        $this->capped = false;
    }

    private function frameKey(Frame $frame): string
    {
        return implode("\0", [
            $frame->function ?? '',
            $frame->class ?? '',
            $frame->file ?? '',
            $frame->line,
            $frame->closureLine ?? '',
        ]);
    }
}
