<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Profile\Profile;
use Tracewick\Transport\Delivery;

/**
 * Sends a profile to one backend: picks the encoder, applies the backend's rules for names and
 * labels, and hands the bytes to a transport.
 *
 * Implementations never throw; failures are reported through the returned {@see Delivery}.
 */
interface Sink
{
    public function send(Profile $profile): Delivery;
}
