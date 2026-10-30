<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use Tracewick\Profile\Profile;
use Tracewick\Sink\Sink;
use Tracewick\Transport\Delivery;

/**
 * Sink that keeps the profiles it is given, optionally throwing for some of them.
 */
final class RecordingSink implements Sink
{
    /** @var list<Profile> */
    public array $sent = [];

    /** @var list<callable(): void> */
    public array $onSend = [];

    /** @var list<Delivery> answers to the next calls; delivered once they run out */
    public array $deliveries = [];

    /**
     * @param list<int> $failOn zero-based calls that throw instead of returning
     */
    public function __construct(private readonly array $failOn = []) {}

    public function send(Profile $profile): Delivery
    {
        $call = \count($this->sent);
        $this->sent[] = $profile;
        foreach ($this->onSend as $callback) {
            $callback();
        }

        if (\in_array($call, $this->failOn, true)) {
            throw new \RuntimeException(\sprintf('Send %d failed.', $call));
        }

        return array_shift($this->deliveries) ?? Delivery::delivered();
    }

    /**
     * Function names of the single frame of each sent profile, in order.
     *
     * @return list<string>
     */
    public function functions(): array
    {
        return array_map(static fn(Profile $profile): string => ($profile->frames[0] ?? null)?->name() ?? '', $this->sent);
    }
}
