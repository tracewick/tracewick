<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;

#[CoversClass(Profile::class)]
#[CoversClass(Stack::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
final class ProfileTest extends TestCase
{
    private const PERIOD_NS = 10_000_000;

    public function testEmptyBatchGivesEmptyProfile(): void
    {
        $profile = Profile::fromBatch(new SampleBatch(EventType::Cpu, self::PERIOD_NS, 1_000, 5_000));

        self::assertTrue($profile->isEmpty());
        self::assertSame([], $profile->frames);
        self::assertSame([], $profile->stacks);
        self::assertSame(EventType::Cpu, $profile->eventType);
        self::assertSame(self::PERIOD_NS, $profile->periodNs);
        self::assertSame(1_000, $profile->startNs);
        self::assertSame(5_000, $profile->endNs);
        self::assertSame(4_000, $profile->durationNs());
        self::assertSame(0, $profile->eventCount());
        self::assertSame(0, $profile->sampledNs());
        self::assertTrue($profile->labels->isEmpty());
    }

    public function testMergesIdenticalStacksAndInternsFrames(): void
    {
        $main = new Frame(null, null, '/app/index.php', 3);
        $handle = new Frame('handle', 'App\Kernel', '/app/Kernel.php', 10);
        $query = new Frame('query', 'App\Db', '/app/Db.php', 20);
        $render = new Frame('render', 'App\View', '/app/View.php', 30);

        $profile = Profile::fromBatch(new SampleBatch(EventType::Wall, self::PERIOD_NS, 0, 100, [
            new Sample([$main, $handle, $query], 1, 10),
            new Sample([$main, $handle, $render], 2, 20),
            // Equal but distinct objects must be interned to the same frames.
            new Sample([
                new Frame(null, null, '/app/index.php', 3),
                new Frame('handle', 'App\Kernel', '/app/Kernel.php', 10),
                new Frame('query', 'App\Db', '/app/Db.php', 20),
            ], 3, 30),
        ]));

        self::assertEquals([$main, $handle, $query, $render], $profile->frames);
        self::assertEquals([
            new Stack([0, 1, 2], 4),
            new Stack([0, 1, 3], 2),
        ], $profile->stacks);
        self::assertSame(6, $profile->eventCount());
        self::assertSame(6 * self::PERIOD_NS, $profile->sampledNs());
        self::assertArrayHasKey(1, $profile->stacks);
        self::assertEquals([$main, $handle, $render], $profile->framesOf($profile->stacks[1]));
    }

    public function testDistinguishesFramesByLine(): void
    {
        $profile = Profile::fromBatch(new SampleBatch(EventType::Wall, self::PERIOD_NS, 0, 100, [
            new Sample([new Frame('work', null, '/app/w.php', 1)], 1, 10),
            new Sample([new Frame('work', null, '/app/w.php', 2)], 1, 20),
        ]));

        self::assertCount(2, $profile->frames);
        self::assertCount(2, $profile->stacks);
    }

    public function testDistinguishesEveryFrameField(): void
    {
        $profile = Profile::fromBatch(new SampleBatch(EventType::Wall, self::PERIOD_NS, 0, 100, [
            new Sample([new Frame('f', null, '/a.php', 1)], 1, 10),
            new Sample([new Frame('f', 'C', '/a.php', 1)], 1, 10),
            new Sample([new Frame('f', null, '/b.php', 1)], 1, 10),
            new Sample([new Frame('f', null, '/a.php', 1, 5)], 1, 10),
            new Sample([new Frame('g', null, '/a.php', 1)], 1, 10),
        ]));

        self::assertCount(5, $profile->frames);
    }

    public function testKeepsTruncatedStacksApart(): void
    {
        $frame = new Frame('deep');
        $other = new Frame('other');

        $profile = Profile::fromBatch(new SampleBatch(EventType::Wall, self::PERIOD_NS, 0, 100, [
            new Sample([$frame], 1, 10),
            new Sample([$frame], 1, 20, true),
            new Sample([$frame], 1, 30, true),
            new Sample([$other], 4, 40, true),
        ]));

        self::assertEquals([new Stack([0], 1), new Stack([0], 2, true), new Stack([1], 4, true)], $profile->stacks);
    }

    public function testKeepsSamplesWithoutFrames(): void
    {
        $profile = Profile::fromBatch(new SampleBatch(EventType::Wall, self::PERIOD_NS, 0, 100, [
            new Sample([], 2, 10),
        ]));

        self::assertEquals([new Stack([], 2)], $profile->stacks);
        self::assertArrayHasKey(0, $profile->stacks);
        self::assertSame([], $profile->framesOf($profile->stacks[0]));
    }

    public function testAttachesAndReplacesLabels(): void
    {
        $batch = new SampleBatch(EventType::Wall, self::PERIOD_NS, 0, 100, [new Sample([new Frame('f')], 1, 10)]);

        $profile = Profile::fromBatch($batch, new Labels(['service' => 'shop']));
        $relabelled = $profile->withLabels(new Labels(['env' => 'prod']));

        self::assertSame(['service' => 'shop'], $profile->labels->toArray());
        self::assertSame(['env' => 'prod'], $relabelled->labels->toArray());
        self::assertSame($profile->stacks, $relabelled->stacks);
        self::assertSame($profile->frames, $relabelled->frames);
        self::assertSame($profile->startNs, $relabelled->startNs);
    }

    public function testFramesOfIgnoresUnknownIds(): void
    {
        $frame = new Frame('f');
        $stack = new Stack([0, 7], 1);
        $profile = new Profile(EventType::Wall, self::PERIOD_NS, 0, 100, [$frame], [$stack]);

        self::assertSame([$frame], $profile->framesOf($stack));
    }

    public function testDurationIsNeverNegative(): void
    {
        self::assertSame(0, (new Profile(EventType::Wall, self::PERIOD_NS, 100, 50))->durationNs());
    }
}
