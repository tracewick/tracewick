<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sampler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\StackAggregator;

#[CoversClass(StackAggregator::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
final class StackAggregatorTest extends TestCase
{
    public function testMergesIdenticalStacks(): void
    {
        $aggregator = new StackAggregator(10);
        $frames = [new Frame('handle')];

        $aggregator->add($frames, 2);
        $aggregator->add($frames, 3);

        $samples = $aggregator->toSamples(1_000);
        self::assertCount(1, $samples);
        self::assertSame(5, $samples[0]->count);
        self::assertSame(1_000, $samples[0]->timestampNs);
        self::assertFalse($aggregator->wasCapped());
    }

    public function testCapsUniqueStacksWithOverflow(): void
    {
        $aggregator = new StackAggregator(2);
        $aggregator->add([new Frame('a')], 1);
        $aggregator->add([new Frame('b')], 2);
        $aggregator->add([new Frame('c')], 4);
        $aggregator->add([new Frame('a')], 8);

        $samples = $aggregator->toSamples(0);
        self::assertTrue($aggregator->wasCapped());
        self::assertCount(3, $samples);
        self::assertSame(9, $samples[0]->count);
        self::assertSame(2, $samples[1]->count);
        self::assertNotEmpty($samples[2]->frames);
        self::assertSame(StackAggregator::OVERFLOW_FUNCTION, $samples[2]->frames[0]->function);
        self::assertSame(4, $samples[2]->count);
        self::assertTrue($samples[2]->truncated);
    }

    public function testResetClearsState(): void
    {
        $aggregator = new StackAggregator(1);
        $aggregator->add([new Frame('a')], 1);
        $aggregator->add([new Frame('b')], 1);
        $aggregator->reset();

        self::assertTrue($aggregator->isEmpty());
        self::assertFalse($aggregator->wasCapped());
        self::assertSame([], $aggregator->toSamples(0));
    }
}
