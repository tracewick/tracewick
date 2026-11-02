<?php

declare(strict_types=1);

namespace Tracewick\Profile;

use Tracewick\Exception\LogicException;

/**
 * Merges profiles that share an aggregation key ([ADR 12](../../docs/adr/0012-aggregate-before-delivery.md)).
 *
 * Stacks with the same sequence of frames have their weights added. The merged window is the
 * earliest start and the latest end. Every profile must share event type, period and labels.
 * Structural caps come from {@see BoundedProfileBuilder}; excess weight becomes `[overflow]`.
 */
final readonly class ProfileMerger
{
    public function __construct(
        private int $maxStacks = BoundedProfileBuilder::DEFAULT_MAX_STACKS,
        private int $maxFrames = BoundedProfileBuilder::DEFAULT_MAX_FRAMES,
        private int $maxStrings = BoundedProfileBuilder::DEFAULT_MAX_STRINGS,
    ) {}

    /**
     * @param non-empty-list<Profile> $profiles
     *
     * @throws LogicException when the profiles cannot be merged
     */
    public function merge(array $profiles): Profile
    {
        $first = $profiles[0];
        $startNs = $first->startNs;
        $endNs = $first->endNs;
        $builder = new BoundedProfileBuilder($this->maxStacks, $this->maxFrames, $this->maxStrings);

        foreach ($profiles as $profile) {
            if ($profile->eventType !== $first->eventType || $profile->periodNs !== $first->periodNs) {
                throw new LogicException('Profiles to merge must share event type and period.');
            }

            $startNs = min($startNs, $profile->startNs);
            $endNs = max($endNs, $profile->endNs);

            foreach ($profile->stacks as $stack) {
                $frames = [];
                foreach ($stack->frameIds as $oldId) {
                    if (!isset($profile->frames[$oldId])) {
                        throw new LogicException(\sprintf('Stack references missing frame %d.', $oldId));
                    }

                    $frames[] = $profile->frames[$oldId];
                }

                $builder->add($frames, $stack->count, $stack->truncated);
            }
        }

        [$frames, $stacks] = $builder->toProfileParts();

        return new Profile(
            $first->eventType,
            $first->periodNs,
            $startNs,
            $endNs,
            $frames,
            $stacks,
            $first->labels,
        );
    }
}
