<?php

declare(strict_types=1);

namespace Tracewick\Encoder;

use Tracewick\Profile\Stack;
use Tracewick\Sampler\Frame;

/**
 * Frames and stacks decoded from a compact spool payload, before wrapping into a {@see \Tracewick\Profile\Profile}.
 */
final readonly class ProfileParts
{
    /**
     * @param list<Frame> $frames
     * @param list<Stack> $stacks
     */
    public function __construct(
        public array $frames,
        public array $stacks,
    ) {}
}
