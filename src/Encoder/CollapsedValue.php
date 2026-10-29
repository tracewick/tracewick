<?php

declare(strict_types=1);

namespace Tracewick\Encoder;

/**
 * Which number follows each stack in the collapsed format.
 */
enum CollapsedValue: string
{
    /**
     * Number of sampling periods, as produced by most collapse scripts.
     */
    case Samples = 'samples';

    /**
     * Estimated time in nanoseconds: samples times the sampling period.
     */
    case Nanoseconds = 'nanoseconds';
}
