<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

/**
 * A call stack observed by the sampler, with how many sampling periods it represents.
 */
final readonly class Sample
{
    /**
     * @param list<Frame> $frames      the call stack, outermost caller first
     * @param int         $count       number of sampling periods this stack represents; more
     *                                 than one when timer events overran while PHP was busy
     * @param int         $timestampNs Unix time of the sample in nanoseconds
     * @param bool        $truncated   whether the stack reached the depth limit, so outer
     *                                 callers may be missing
     */
    public function __construct(
        public array $frames,
        public int $count,
        public int $timestampNs,
        public bool $truncated = false,
    ) {}
}
