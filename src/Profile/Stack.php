<?php

declare(strict_types=1);

namespace Tracewick\Profile;

/**
 * A distinct call stack of a profile and how often it was observed.
 */
final readonly class Stack
{
    /**
     * @param list<int> $frameIds  indexes into {@see Profile::$frames}, outermost caller first
     * @param int       $count     number of sampling periods in which this stack was observed
     * @param bool      $truncated whether the stack hit the sampler depth limit, so outer callers
     *                             are missing
     */
    public function __construct(
        public array $frameIds,
        public int $count,
        public bool $truncated = false,
    ) {}
}
