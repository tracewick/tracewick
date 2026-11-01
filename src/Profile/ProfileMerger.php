<?php

declare(strict_types=1);

namespace Tracewick\Profile;

use Tracewick\Exception\LogicException;
use Tracewick\Sampler\Frame;

/**
 * Merges profiles that share an aggregation key ([ADR 12](../../docs/adr/0012-aggregate-before-delivery.md)).
 *
 * Stacks with the same sequence of frames have their weights added. The merged window is the
 * earliest start and the latest end. Every profile must share event type, period and labels.
 */
final readonly class ProfileMerger
{
    /**
     * @param non-empty-list<Profile> $profiles
     *
     * @throws LogicException when the profiles cannot be merged
     */
    public function merge(array $profiles): Profile
    {
        $first = $profiles[0];
        $frameIds = [];
        $frames = [];
        /** @var array<string, array{list<int>, int, bool}> $stacks */
        $stacks = [];
        $startNs = $first->startNs;
        $endNs = $first->endNs;

        foreach ($profiles as $profile) {
            if ($profile->eventType !== $first->eventType || $profile->periodNs !== $first->periodNs) {
                throw new LogicException('Profiles to merge must share event type and period.');
            }

            $startNs = min($startNs, $profile->startNs);
            $endNs = max($endNs, $profile->endNs);
            $idMap = [];
            foreach ($profile->frames as $oldId => $frame) {
                $key = $this->frameKey($frame);
                if (!isset($frameIds[$key])) {
                    $frameIds[$key] = \count($frames);
                    $frames[] = $frame;
                }

                $idMap[$oldId] = $frameIds[$key];
            }

            foreach ($profile->stacks as $stack) {
                $ids = [];
                foreach ($stack->frameIds as $oldId) {
                    if (!isset($idMap[$oldId])) {
                        throw new LogicException(\sprintf('Stack references missing frame %d.', $oldId));
                    }

                    $ids[] = $idMap[$oldId];
                }

                $stackKey = implode(',', $ids);
                if ($stack->truncated) {
                    $stackKey .= ',truncated';
                }

                if (isset($stacks[$stackKey])) {
                    $stacks[$stackKey][1] += $stack->count;
                } else {
                    $stacks[$stackKey] = [$ids, $stack->count, $stack->truncated];
                }
            }
        }

        return new Profile(
            $first->eventType,
            $first->periodNs,
            $startNs,
            $endNs,
            $frames,
            array_values(array_map(
                static fn(array $stack): Stack => new Stack($stack[0], $stack[1], $stack[2]),
                $stacks,
            )),
            $first->labels,
        );
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
