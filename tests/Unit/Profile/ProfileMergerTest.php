<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\ProfileMerger;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;

#[CoversClass(ProfileMerger::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(LogicException::class)]
final class ProfileMergerTest extends TestCase
{
    public function testAddsWeightsForEqualStacksAndWidensTheWindow(): void
    {
        $frame = new Frame('work');
        $a = new Profile(EventType::Wall, 10_000_000, 100, 200, [$frame], [new Stack([0], 2)], new Labels(['env' => 'prod']));
        $b = new Profile(EventType::Wall, 10_000_000, 150, 300, [$frame], [new Stack([0], 5)], new Labels(['env' => 'prod']));

        $merged = (new ProfileMerger())->merge([$a, $b]);

        self::assertSame(100, $merged->startNs);
        self::assertSame(300, $merged->endNs);
        self::assertCount(1, $merged->stacks);
        self::assertSame(7, $merged->stacks[0]->count);
        self::assertSame(['env' => 'prod'], $merged->labels->toArray());
    }

    public function testKeepsDistinctStacks(): void
    {
        $a = new Profile(EventType::Cpu, 1, 1, 2, [new Frame('a')], [new Stack([0], 1)]);
        $b = new Profile(EventType::Cpu, 1, 1, 2, [new Frame('b')], [new Stack([0], 3)]);

        $merged = (new ProfileMerger())->merge([$a, $b]);

        self::assertCount(2, $merged->frames);
        self::assertCount(2, $merged->stacks);
        self::assertSame(4, $merged->eventCount());
    }

    public function testRejectsMismatchedPeriods(): void
    {
        $a = new Profile(EventType::Wall, 10, 1, 2, [new Frame('a')], [new Stack([0], 1)]);
        $b = new Profile(EventType::Wall, 20, 1, 2, [new Frame('a')], [new Stack([0], 1)]);

        $this->expectException(LogicException::class);

        (new ProfileMerger())->merge([$a, $b]);
    }
}
