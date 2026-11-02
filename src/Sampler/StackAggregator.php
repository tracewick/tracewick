<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

use Tracewick\Profile\BoundedProfileBuilder;

/**
 * Merges stacks by identity into counts without keeping one Sample per hit.
 *
 * Used by {@see ExcimerSampler} while the native flush callback drains the Excimer log, so a long
 * profile stays proportional to unique stacks rather than to sample count. Admission is
 * transactional via {@see BoundedProfileBuilder}: rejected stacks do not intern frames.
 *
 * @internal
 */
final readonly class StackAggregator
{
    public const OVERFLOW_FUNCTION = BoundedProfileBuilder::OVERFLOW_FUNCTION;

    private BoundedProfileBuilder $builder;

    public function __construct(
        int $maxUniqueStacks,
        int $maxUniqueFrames = BoundedProfileBuilder::DEFAULT_MAX_FRAMES,
        int $maxUniqueStrings = BoundedProfileBuilder::DEFAULT_MAX_STRINGS,
    ) {
        $this->builder = new BoundedProfileBuilder($maxUniqueStacks, $maxUniqueFrames, $maxUniqueStrings);
    }

    /**
     * @param list<Frame> $frames outermost caller first
     */
    public function add(array $frames, int $count, bool $truncated = false): void
    {
        $this->builder->add($frames, $count, $truncated);
    }

    /**
     * @return list<Sample>
     */
    public function toSamples(int $timestampNs): array
    {
        [$frames, $stacks] = $this->builder->toProfileParts();
        $samples = [];
        foreach ($stacks as $stack) {
            $stackFrames = [];
            foreach ($stack->frameIds as $id) {
                // Ids come from toProfileParts(); every stack id indexes $frames.
                $stackFrames[] = $frames[$id]; // @phpstan-ignore offsetAccess.notFound
            }

            $samples[] = new Sample($stackFrames, $stack->count, $timestampNs, $stack->truncated);
        }

        return $samples;
    }

    public function isEmpty(): bool
    {
        return $this->builder->isEmpty();
    }

    public function uniqueStackCount(): int
    {
        return $this->builder->uniqueStackCount();
    }

    public function wasCapped(): bool
    {
        return $this->builder->wasCapped();
    }

    public function frameCount(): int
    {
        return $this->builder->frameCount();
    }

    public function reset(): void
    {
        $this->builder->reset();
    }
}
