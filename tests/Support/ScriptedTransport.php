<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use PHPUnit\Framework\Assert;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\Payload;
use Tracewick\Transport\Transport;

/**
 * Transport that records what it is asked to send and answers with scripted deliveries.
 */
final class ScriptedTransport implements Transport
{
    /** @var list<Payload> */
    public array $sent = [];

    /** @var list<Delivery|\Throwable> */
    private array $script;

    public function __construct(Delivery|\Throwable ...$script)
    {
        $this->script = array_values($script);
    }

    public function send(Payload $payload): Delivery
    {
        $this->sent[] = $payload;
        $next = array_shift($this->script) ?? Assert::fail('The transport was called more often than scripted.');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * Returns the payload of the given call.
     */
    public function payload(int $index = 0): Payload
    {
        return $this->sent[$index] ?? Assert::fail(\sprintf('The transport was not called %d times.', $index + 1));
    }
}
