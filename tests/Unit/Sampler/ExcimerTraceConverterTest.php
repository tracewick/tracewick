<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sampler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Sampler\ExcimerTraceConverter;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;

#[CoversClass(ExcimerTraceConverter::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
final class ExcimerTraceConverterTest extends TestCase
{
    private const ORIGIN_NS = 1_700_000_000_000_000_000;

    public function testOrdersFramesFromOutermostCaller(): void
    {
        $sample = $this->converter()->convertEntry([
            ['file' => '/app/src/Repo.php', 'line' => 30, 'class' => 'App\Repo', 'function' => 'find'],
            ['file' => '/app/src/Controller.php', 'line' => 12, 'class' => 'App\Controller', 'function' => 'show'],
            ['file' => '/app/public/index.php', 'line' => 5],
        ], 1, 0.5);

        self::assertNotNull($sample);
        self::assertEquals([
            new Frame(null, null, '/app/public/index.php', 5),
            new Frame('show', 'App\Controller', '/app/src/Controller.php', 12),
            new Frame('find', 'App\Repo', '/app/src/Repo.php', 30),
        ], $sample->frames);
        self::assertSame(1, $sample->count);
        self::assertFalse($sample->truncated);
    }

    public function testConvertsRelativeTimestampToUnixNanoseconds(): void
    {
        $sample = $this->converter()->convertEntry([['function' => 'main']], 1, 1.25);

        self::assertNotNull($sample);
        self::assertSame(self::ORIGIN_NS + 1_250_000_000, $sample->timestampNs);
    }

    public function testKeepsOverrunCount(): void
    {
        $sample = $this->converter()->convertEntry([['function' => 'main']], 4, 0.0);

        self::assertNotNull($sample);
        self::assertSame(4, $sample->count);
    }

    public function testDropsEntriesWithoutEvents(): void
    {
        self::assertNull($this->converter()->convertEntry([['function' => 'main']], 0, 0.0));
        self::assertNull($this->converter()->convertEntry([['function' => 'main']], -1, 0.0));
    }

    public function testKeepsClosureDefinitionLine(): void
    {
        $sample = $this->converter()->convertEntry([
            ['file' => '/app/routes.php', 'line' => 8, 'function' => '{closure}', 'closure_line' => 7],
        ], 1, 0.0);

        self::assertNotNull($sample);
        self::assertEquals([new Frame('{closure}', null, '/app/routes.php', 8, 7)], $sample->frames);
    }

    public function testSkipsMalformedFramesAndFields(): void
    {
        $sample = $this->converter()->convertEntry([
            'not a frame',
            ['file' => 42, 'line' => '7', 'class' => '', 'function' => ['x'], 'closure_line' => '3'],
            ['function' => 'caller'],
        ], 1, 0.0);

        self::assertNotNull($sample);
        self::assertEquals([new Frame('caller'), new Frame(null)], $sample->frames);
    }

    public function testKeepsEntriesWithEmptyTrace(): void
    {
        $sample = $this->converter()->convertEntry([], 2, 0.0);

        self::assertNotNull($sample);
        self::assertSame([], $sample->frames);
        self::assertSame(2, $sample->count);
    }

    public function testMarksStacksThatReachTheDepthLimit(): void
    {
        $converter = new ExcimerTraceConverter(self::ORIGIN_NS, 2);

        $short = $converter->convertEntry([['function' => 'a']], 1, 0.0);
        $full = $converter->convertEntry([['function' => 'a'], ['function' => 'b']], 1, 0.0);

        self::assertNotNull($short);
        self::assertNotNull($full);
        self::assertFalse($short->truncated);
        self::assertTrue($full->truncated);
    }

    private function converter(): ExcimerTraceConverter
    {
        return new ExcimerTraceConverter(self::ORIGIN_NS, 256);
    }
}
