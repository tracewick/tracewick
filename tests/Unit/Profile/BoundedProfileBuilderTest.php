<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\Frame;

#[CoversClass(BoundedProfileBuilder::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Stack::class)]
#[UsesClass(LogicException::class)]
final class BoundedProfileBuilderTest extends TestCase
{
    public function testMergesIdenticalStacksAndPreservesWeightInvariant(): void
    {
        $builder = new BoundedProfileBuilder(10);
        $frames = [new Frame('handle')];

        $builder->add($frames, 2);
        $builder->add($frames, 3);

        self::assertSame(5, $builder->inWeight());
        self::assertSame(5, $builder->keptWeight());
        self::assertSame(0, $builder->overflowWeight());
        self::assertSame($builder->inWeight(), $builder->keptWeight() + $builder->overflowWeight());

        [$outFrames, $stacks] = $builder->toProfileParts();
        self::assertCount(1, $outFrames);
        self::assertCount(1, $stacks);
        self::assertSame(5, $stacks[0]->count);
    }

    public function testDoesNotInternFramesOfOverflowedStacks(): void
    {
        $builder = new BoundedProfileBuilder(1);
        $builder->add([new Frame('kept')], 1);
        $framesAfterFirst = $builder->frameCount();

        for ($i = 0; $i < 50; ++$i) {
            $builder->add([new Frame('flood_' . $i, file: '/tmp/' . $i . '.php')], 1);
        }

        self::assertTrue($builder->wasCapped());
        self::assertSame($framesAfterFirst, $builder->frameCount());
        self::assertSame(1, $builder->stringCount());
        self::assertSame(51, $builder->inWeight());
        self::assertSame(1, $builder->keptWeight());
        self::assertSame(50, $builder->overflowWeight());
        self::assertSame($builder->inWeight(), $builder->keptWeight() + $builder->overflowWeight());

        [$frames, $stacks] = $builder->toProfileParts();
        self::assertCount(2, $frames);
        self::assertSame('kept', $frames[0]->function);
        self::assertSame(BoundedProfileBuilder::OVERFLOW_FUNCTION, $frames[1]->function);
        self::assertCount(2, $stacks);
        self::assertSame(50, $stacks[1]->count);
    }

    public function testFrameCapRejectsWithoutGrowingTables(): void
    {
        $builder = new BoundedProfileBuilder(100, 1, 100);
        $builder->add([new Frame('a')], 1);
        $before = $builder->frameCount();

        $builder->add([new Frame('b')], 2);

        self::assertTrue($builder->wasCapped());
        self::assertSame($before, $builder->frameCount());
        self::assertSame(2, $builder->overflowWeight());
    }

    public function testStringCapRejectsWithoutGrowingTables(): void
    {
        $builder = new BoundedProfileBuilder(100, 100, 1);
        $builder->add([new Frame('only')], 1);
        $beforeFrames = $builder->frameCount();
        $beforeStrings = $builder->stringCount();

        $builder->add([new Frame('other')], 3);

        self::assertTrue($builder->wasCapped());
        self::assertSame($beforeFrames, $builder->frameCount());
        self::assertSame($beforeStrings, $builder->stringCount());
        self::assertSame(3, $builder->overflowWeight());
    }

    public function testRejectsInvalidStackCap(): void
    {
        $this->expectException(LogicException::class);

        new BoundedProfileBuilder(0);
    }

    public function testRejectsInvalidFrameCap(): void
    {
        $this->expectException(LogicException::class);

        new BoundedProfileBuilder(10, 0);
    }

    public function testRejectsInvalidStringCap(): void
    {
        $this->expectException(LogicException::class);

        new BoundedProfileBuilder(10, 10, 0);
    }

    public function testIgnoresNonPositiveWeight(): void
    {
        $builder = new BoundedProfileBuilder(10);
        $builder->add([new Frame('a')], 0);
        $builder->add([new Frame('a')], -2);

        self::assertTrue($builder->isEmpty());
        self::assertSame(0, $builder->inWeight());
    }

    public function testReusesPendingFrameAndStringWithinOneStack(): void
    {
        $builder = new BoundedProfileBuilder(10);
        $frame = new Frame('same', class: 'same', file: 'same');
        $builder->add([$frame, $frame], 1);

        self::assertSame(1, $builder->frameCount());
        self::assertSame(1, $builder->stringCount());
        self::assertSame(1, $builder->uniqueStackCount());
    }

    public function testReusesExistingStringsAcrossStacks(): void
    {
        $builder = new BoundedProfileBuilder(10);
        $builder->add([new Frame('a', file: '/shared.php')], 1);
        $builder->add([new Frame('b', file: '/shared.php')], 1);

        self::assertSame(2, $builder->frameCount());
        self::assertSame(3, $builder->stringCount());
    }

    public function testSkipsEmptyFrameStrings(): void
    {
        $builder = new BoundedProfileBuilder(10);
        $builder->add([new Frame('', class: '', file: '')], 1);

        self::assertSame(1, $builder->frameCount());
        self::assertSame(0, $builder->stringCount());
    }

    public function testKeepsTruncatedStacksSeparate(): void
    {
        $builder = new BoundedProfileBuilder(10);
        $frames = [new Frame('work')];
        $builder->add($frames, 1, false);
        $builder->add($frames, 2, true);

        self::assertSame(2, $builder->uniqueStackCount());
        self::assertSame(3, $builder->keptWeight());
    }

    public function testResetClearsWeightsAndTables(): void
    {
        $builder = new BoundedProfileBuilder(1);
        $builder->add([new Frame('a')], 1);
        $builder->add([new Frame('b')], 1);
        self::assertSame(2, $builder->uniqueStackCount());
        $builder->reset();

        self::assertTrue($builder->isEmpty());
        self::assertSame(0, $builder->inWeight());
        self::assertFalse($builder->wasCapped());
        self::assertSame([[], []], $builder->toProfileParts());
    }
}
