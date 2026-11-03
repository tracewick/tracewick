<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Delivers encoded bytes somewhere: a backend over HTTP, a file, a local agent.
 *
 * Transports know nothing about formats or backends; a sink decides what to send and how to
 * describe it. Implementations never throw: every failure is reported through the returned
 * {@see Delivery}, so a broken or slow destination cannot break the host application (ADR 5).
 */
interface Transport
{
    public function send(Payload $payload): Delivery;
}
