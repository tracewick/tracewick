<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sampler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;

#[CoversClass(SampleBatch::class)]
#[UsesClass(Sample::class)]
#[UsesClass(Frame::class)]
final class SampleBatchTest extends TestCase
{
    public function testEmptyBatch(): void
    {
        $batch = new SampleBatch(EventType::Cpu, 10_000_000, 100, 200);

        self::assertTrue($batch->isEmpty());
        self::assertSame(0, $batch->eventCount());
        self::assertSame(EventType::Cpu, $batch->eventType);
        self::assertSame(10_000_000, $batch->periodNs);
        self::assertSame(100, $batch->startNs);
        self::assertSame(200, $batch->endNs);
    }

    public function testCountsEventsIncludingOverruns(): void
    {
        $batch = new SampleBatch(EventType::Wall, 10_000_000, 100, 200, [
            new Sample([new Frame('a')], 1, 110),
            new Sample([new Frame('b')], 3, 120),
        ]);

        self::assertFalse($batch->isEmpty());
        self::assertSame(4, $batch->eventCount());
    }
}
