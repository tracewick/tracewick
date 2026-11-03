<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\ProfileMerger;
use Tracewick\Profile\Stack;
use Tracewick\Runtime\ProfileAccumulator;
use Tracewick\Runtime\SpoolAggregationWindow;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sink\SpoolAggregationKey;

#[CoversClass(ProfileAccumulator::class)]
#[UsesClass(SpoolAggregationWindow::class)]
#[UsesClass(SpoolAggregationKey::class)]
#[UsesClass(ProfileMerger::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
final class ProfileAccumulatorTest extends TestCase
{
    private const WINDOW_NS = 15_000_000_000;

    public function testMergesProfilesThatShareAKeyAndWindow(): void
    {
        $accumulator = new ProfileAccumulator('blog', new SpoolAggregationWindow(15));
        $accumulator->add($this->profile(1_000_000_000, 2_000_000_000, 'a', 2));
        $accumulator->add($this->profile(3_000_000_000, 4_000_000_000, 'a', 3));

        self::assertSame(1, $accumulator->openBuckets());
        $closed = $accumulator->drainClosed(self::WINDOW_NS + 1);
        self::assertCount(1, $closed);
        $merged = $closed[0];
        self::assertNotEmpty($merged->stacks);
        self::assertSame(5, $merged->stacks[0]->count);
        self::assertSame(1_000_000_000, $merged->startNs);
        self::assertSame(4_000_000_000, $merged->endNs);
        self::assertSame(0, $accumulator->openBuckets());
    }

    public function testKeepsSeparateBucketsForDifferentLabels(): void
    {
        $accumulator = new ProfileAccumulator('blog');
        $accumulator->add($this->profile(1_000_000_000, 2_000_000_000, 'a', 1, new Labels(['route' => '/a'])));
        $accumulator->add($this->profile(1_000_000_000, 2_000_000_000, 'b', 1, new Labels(['route' => '/b'])));

        self::assertSame(2, $accumulator->openBuckets());
        $closed = $accumulator->drainClosed(self::WINDOW_NS + 1);
        self::assertCount(2, $closed);
    }

    public function testDoesNotDrainAnOpenWindow(): void
    {
        $accumulator = new ProfileAccumulator('blog');
        $accumulator->add($this->profile(1_000_000_000, 2_000_000_000, 'a', 1));

        self::assertSame([], $accumulator->drainClosed(self::WINDOW_NS - 1));
        self::assertSame(1, $accumulator->openBuckets());
    }

    public function testDrainAllEmptiesOpenBuckets(): void
    {
        $accumulator = new ProfileAccumulator('blog');
        $accumulator->add($this->profile(1_000_000_000, 2_000_000_000, 'a', 1));

        $all = $accumulator->drainAll();
        self::assertCount(1, $all);
        self::assertSame(0, $accumulator->openBuckets());
    }

    public function testDropsNewBucketsWhenTheCapIsReached(): void
    {
        $accumulator = new ProfileAccumulator('blog', maxOpenBuckets: 1);
        $accumulator->add($this->profile(1_000_000_000, 2_000_000_000, 'a', 1, new Labels(['route' => '/a'])));
        $accumulator->add($this->profile(1_000_000_000, 2_000_000_000, 'b', 1, new Labels(['route' => '/b'])));

        self::assertSame(1, $accumulator->openBuckets());
        self::assertSame(1, $accumulator->droppedProfiles());
    }

    public function testIgnoresEmptyProfiles(): void
    {
        $accumulator = new ProfileAccumulator('blog');
        $accumulator->add(new Profile(EventType::Wall, 10_000_000, 1, 2));

        self::assertSame(0, $accumulator->openBuckets());
    }

    private function profile(int $startNs, int $endNs, string $function, int $count, Labels $labels = new Labels()): Profile
    {
        return new Profile(
            EventType::Wall,
            10_000_000,
            $startNs,
            $endNs,
            [new Frame($function)],
            [new Stack([0], $count)],
            $labels,
        );
    }
}
