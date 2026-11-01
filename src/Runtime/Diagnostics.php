<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Sampler\NullSamplerReason;

/**
 * What a {@see Profiler} has done since it was created, for metrics and debugging.
 *
 * Under PHP-FPM a profiler lives for one request, so the numbers cover that request; a
 * long-running worker accumulates them for its whole life.
 */
final readonly class Diagnostics
{
    /**
     * @param int                     $collectedProfiles profiles that ended with samples
     * @param int                     $droppedProfiles   profiles dropped because the buffer was full
     * @param int                     $deliveredProfiles profiles the sink delivered
     * @param int                     $rejectedProfiles  profiles the backend refused
     * @param int                     $failedProfiles    profiles that could not be delivered
     * @param int                     $skippedProfiles   profiles the sink did not try to deliver, such as while its
     *                                                   circuit breaker is open or its spool is full
     * @param int                     $swallowedErrors   exceptions the profiler caught to keep the application running
     * @param ?NullSamplerReason      $inactiveSampler   why a no-op sampler is in use, or null when sampling is active
     * @param int                     $samplerFailures   failures swallowed by the sampler since it was created
     */
    public function __construct(
        public int $collectedProfiles = 0,
        public int $droppedProfiles = 0,
        public int $deliveredProfiles = 0,
        public int $rejectedProfiles = 0,
        public int $failedProfiles = 0,
        public int $skippedProfiles = 0,
        public int $swallowedErrors = 0,
        public ?NullSamplerReason $inactiveSampler = null,
        public int $samplerFailures = 0,
    ) {}
}
