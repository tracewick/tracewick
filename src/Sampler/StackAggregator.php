<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

/**
 * Merges stacks by identity into counts without keeping one Sample per hit.
 *
 * Used by {@see ExcimerSampler} while the native flush callback drains the Excimer log, so a long
 * profile stays proportional to unique stacks rather than to sample count.
 *
 * @internal
 */
final class StackAggregator
{
    public const OVERFLOW_FUNCTION = '[overflow]';

    /** @var array<string, int> */
    private array $frameIds = [];

    /** @var list<Frame> */
    private array $frames = [];

    /** @var array<string, array{list<int>, int, bool}> */
    private array $stacks = [];

    private int $overflowCount = 0;

    private bool $capped = false;

    public function __construct(
        private readonly int $maxUniqueStacks,
    ) {}

    /**
     * @param list<Frame> $frames outermost caller first
     */
    public function add(array $frames, int $count, bool $truncated = false): void
    {
        if ($count < 1) {
            return;
        }

        $ids = [];
        foreach ($frames as $frame) {
            $frameKey = $this->frameKey($frame);
            if (!isset($this->frameIds[$frameKey])) {
                $this->frameIds[$frameKey] = \count($this->frames);
                $this->frames[] = $frame;
            }

            $ids[] = $this->frameIds[$frameKey];
        }

        $stackKey = implode(',', $ids);
        if ($truncated) {
            $stackKey .= ',truncated';
        }

        if (isset($this->stacks[$stackKey])) {
            $this->stacks[$stackKey][1] += $count;

            return;
        }

        if (\count($this->stacks) >= $this->maxUniqueStacks) {
            $this->capped = true;
            $this->overflowCount += $count;

            return;
        }

        $this->stacks[$stackKey] = [$ids, $count, $truncated];
    }

    /**
     * @return list<Sample>
     */
    public function toSamples(int $timestampNs): array
    {
        $samples = [];
        foreach ($this->stacks as [$ids, $count, $truncated]) {
            $frames = [];
            foreach ($ids as $id) {
                if (!isset($this->frames[$id])) {
                    continue;
                }

                $frames[] = $this->frames[$id];
            }

            $samples[] = new Sample($frames, $count, $timestampNs, $truncated);
        }

        if ($this->overflowCount > 0) {
            $samples[] = new Sample(
                [new Frame(self::OVERFLOW_FUNCTION)],
                $this->overflowCount,
                $timestampNs,
                true,
            );
        }

        return $samples;
    }

    public function isEmpty(): bool
    {
        return [] === $this->stacks && 0 === $this->overflowCount;
    }

    public function uniqueStackCount(): int
    {
        return \count($this->stacks) + ($this->overflowCount > 0 ? 1 : 0);
    }

    public function wasCapped(): bool
    {
        return $this->capped;
    }

    public function reset(): void
    {
        $this->frameIds = [];
        $this->frames = [];
        $this->stacks = [];
        $this->overflowCount = 0;
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
