<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use PHPUnit\Framework\Assert;
use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolForwarder;
use Tracewick\Transport\Delivery;

/**
 * Forwarder that records the spool files it is asked to forward and answers with scripted
 * deliveries.
 */
final class ScriptedForwarder implements SpoolForwarder
{
    /** @var list<SpoolFile> */
    public array $forwarded = [];

    /** @var list<Delivery|\Throwable> */
    private array $script;

    public function __construct(Delivery|\Throwable ...$script)
    {
        $this->script = array_values($script);
    }

    public function forward(SpoolFile $file): Delivery
    {
        $this->forwarded[] = $file;
        $next = array_shift($this->script) ?? Assert::fail('The forwarder was called more often than scripted.');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * Payloads of the forwarded files, in order.
     *
     * @return list<string>
     */
    public function payloads(): array
    {
        return array_map(static fn(SpoolFile $file): string => $file->payload, $this->forwarded);
    }
}
