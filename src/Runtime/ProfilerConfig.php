<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;

/**
 * Settings of a {@see Profiler}.
 */
final readonly class ProfilerConfig
{
    public const DEFAULT_MAX_BUFFERED_PROFILES = 16;

    public const MAX_MAX_BUFFERED_PROFILES = 1_000;

    /**
     * @param Labels $labels              labels of every profile, such as `service` and `env`;
     *                                    labels given for a request take precedence
     * @param int    $maxBufferedProfiles profiles kept in memory until the next flush; a profile
     *                                    that does not fit is dropped
     * @param float  $sampleRate          share of units of work that are profiled, from 0 (none) to
     *                                    1 (all); the others run without the sampler
     * @param bool   $finishRequest       whether to end the PHP-FPM response before delivering at
     *                                    shutdown, so that the client does not wait for it; output
     *                                    of shutdown functions that run later is then lost
     *
     * @throws LogicException when a value is invalid
     */
    public function __construct(
        public Labels $labels = new Labels(),
        public int $maxBufferedProfiles = self::DEFAULT_MAX_BUFFERED_PROFILES,
        public float $sampleRate = 1.0,
        public bool $finishRequest = false,
    ) {
        if (is_nan($sampleRate) || $sampleRate < 0.0 || $sampleRate > 1.0) {
            throw new LogicException(\sprintf('The sample rate must be between 0 and 1, got %s.', $sampleRate));
        }

        if ($maxBufferedProfiles < 1 || $maxBufferedProfiles > self::MAX_MAX_BUFFERED_PROFILES) {
            throw new LogicException(\sprintf('The number of buffered profiles must be between 1 and %d, got %d.', self::MAX_MAX_BUFFERED_PROFILES, $maxBufferedProfiles));
        }
    }
}
