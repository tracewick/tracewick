<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Bytes to deliver, with the media type and the parameters that describe them.
 *
 * Parameters are destination metadata, such as the query string of an HTTP request; a transport
 * that has no use for them ignores them.
 */
final readonly class Payload
{
    /**
     * @param string                $body        the bytes to deliver
     * @param string                $contentType media type of the body
     * @param array<string, string> $parameters  metadata describing the body
     */
    public function __construct(
        public string $body,
        public string $contentType,
        public array $parameters = [],
    ) {}
}
