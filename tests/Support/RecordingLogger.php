<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that keeps what it is given.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * Signature stays compatible with psr/log 1.x (prefer-lowest) and 2.x/3.x.
     *
     * @param mixed                $level
     * @param string|\Stringable   $message
     * @param array<mixed>         $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Level and message of each record, in order.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(static fn(array $record): string => \sprintf('%s: %s', \is_string($record['level']) ? $record['level'] : '?', $record['message']), $this->records);
    }
}
