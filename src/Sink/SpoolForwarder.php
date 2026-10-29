<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Transport\Delivery;

/**
 * Sends a profile read from a spool to one backend, applying the backend's rules for names and
 * labels.
 *
 * Implementations never throw. A file the backend could never accept is reported as rejected,
 * and a failure that may pass, such as an unreachable backend, as failed or skipped.
 */
interface SpoolForwarder
{
    public function forward(SpoolFile $file): Delivery;
}
