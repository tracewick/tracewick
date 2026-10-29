<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

/**
 * What the sampling timer measures.
 */
enum EventType: string
{
    /**
     * Wall-clock time: includes time spent waiting on I/O, locks and sleeps.
     */
    case Wall = 'wall';

    /**
     * CPU time consumed by the process: excludes time spent waiting.
     */
    case Cpu = 'cpu';
}
