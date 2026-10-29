<?php

declare(strict_types=1);

namespace Tracewick\Encoder;

use Tracewick\Profile\Profile;

/**
 * Turns a profile into the bytes of one output format.
 *
 * Encoders are pure functions of the profile: the same profile always produces the same bytes,
 * and encoding never changes the profile or touches I/O.
 */
interface Encoder
{
    public function encode(Profile $profile): string;

    /**
     * Media type of the encoded bytes, for HTTP transports and file metadata.
     */
    public function mediaType(): string;
}
