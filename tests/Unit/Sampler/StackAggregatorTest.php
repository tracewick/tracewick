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

    public function testIgnoresNonPositiveCounts(): void
    {
        $aggregator = new StackAggregator(10);
        $aggregator->add([new Frame('a')], 0);
        $aggregator->add([new Frame('a')], -1);

        self::assertTrue($aggregator->isEmpty());
        self::assertSame(0, $aggregator->uniqueStackCount());
    }

    public function testCountsOverflowAsAUniqueStack(): void
    {
        $aggregator = new StackAggregator(1);
        $aggregator->add([new Frame('a')], 1);
        $aggregator->add([new Frame('b')], 2);

        self::assertSame(2, $aggregator->uniqueStackCount());
    }

    public function testKeepsTruncatedStacksApartFromCompleteOnes(): void
    {
        $aggregator = new StackAggregator(10);
        $frames = [new Frame('handle')];

        $aggregator->add($frames, 1, false);
        $aggregator->add($frames, 2, true);
        $aggregator->add($frames, 4, true);

        $samples = $aggregator->toSamples(0);
        self::assertCount(2, $samples);
        self::assertSame(1, $samples[0]->count);
        self::assertFalse($samples[0]->truncated);
        self::assertSame(6, $samples[1]->count);
        self::assertTrue($samples[1]->truncated);
    }
}
