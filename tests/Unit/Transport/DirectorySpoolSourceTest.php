<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\ClaimedSpoolFile;
use Tracewick\Transport\DirectorySpoolSource;
use Tracewick\Transport\SpoolReaderConfig;
use Tracewick\Transport\SpoolTransport;

#[CoversClass(DirectorySpoolSource::class)]
#[CoversClass(ClaimedSpoolFile::class)]
#[UsesClass(SpoolReaderConfig::class)]
#[UsesClass(SpoolTransport::class)]
#[UsesClass(SystemClock::class)]
final class DirectorySpoolSourceTest extends TestCase
{
    private const FIRST = '01798000000000000001-0000000000000000.spool';

    private const SECOND = '01798000000000000002-0000000000000000.spool';

    private const THIRD = '01798000000000000003-0000000000000000.spool';

    private TemporaryDirectory $spool;

    private ManualClock $clock;

    protected function setUp(): void
    {
        $this->spool = new TemporaryDirectory();
        // Claims compare the clock with file modification times, so it starts at the real time.
        $this->clock = new ManualClock(time() * 1_000_000_000);
    }

    protected function tearDown(): void
    {
        $this->spool->remove();
    }

    public function testClaimsTheOldestFileFirst(): void
    {
        $this->write(self::SECOND, 'second');
        $this->write(self::FIRST, 'first');

        $file = $this->source('reader-a')->claim();

        self::assertNotNull($file);
        self::assertSame(self::FIRST, $file->name);
        self::assertSame('first', $file->bytes);
        self::assertSame($this->spool->path . '/.' . self::FIRST . '.claimed-reader-a', $file->handle);
        self::assertSame(['.' . self::FIRST . '.claimed-reader-a', self::SECOND], $this->spool->entries());
    }

    public function testMarksTheClaimWithTheCurrentTime(): void
    {
        $this->write(self::FIRST, 'first', time() - 3_600);
        $this->clock->advanceMs(5_000);

        $file = $this->source()->claim();

        self::assertNotNull($file);
        clearstatcache();
        self::assertSame(intdiv($this->clock->nowNs(), 1_000_000_000), filemtime($file->handle));
    }

    public function testReturnsNullWhenTheSpoolIsEmpty(): void
    {
        self::assertNull($this->source()->claim());
    }

    public function testReturnsNullWhenTheDirectoryDoesNotExist(): void
    {
        $source = new DirectorySpoolSource(new SpoolReaderConfig($this->spool->path . '/missing', 'reader'), $this->clock);

        self::assertNull($source->claim());
    }

    public function testIgnoresEverythingButSpoolFiles(): void
    {
        $this->write('.' . self::FIRST . '.tmp', 'being written');
        $this->write('.hidden.spool', 'hidden');
        $this->write('README', 'notes');
        mkdir($this->spool->path . '/rejected');

        self::assertNull($this->source()->claim());
    }

    public function testClaimsEachFileOnce(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $a = $this->source('reader-a');
        $b = $this->source('reader-b');

        $first = $a->claim();
        $second = $b->claim();

        self::assertSame(self::FIRST, $first?->name);
        self::assertSame(self::SECOND, $second?->name);
        self::assertNull($a->claim());
        self::assertNull($b->claim());
    }

    public function testSkipsAFileAnotherReaderClaimedAfterTheScan(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $this->write(self::THIRD, 'third');
        $a = $this->source('reader-a');
        $b = $this->source('reader-b');

        $a->claim();
        // Reader B scans now and takes the second file, which reader A also has queued.
        $b->claim();

        self::assertSame(self::THIRD, $a->claim()?->name);
    }

    public function testScansAgainWhenTheQueuedFilesAreGone(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $a = $this->source('reader-a');
        $b = $this->source('reader-b');
        $a->claim();
        $b->claim();
        $this->write(self::THIRD, 'third');

        self::assertSame(self::THIRD, $a->claim()?->name);
    }

    public function testFindsFilesWrittenAfterTheQueueRanEmpty(): void
    {
        $source = $this->source();
        self::assertNull($source->claim());

        $this->write(self::FIRST, 'first');

        self::assertSame(self::FIRST, $source->claim()?->name);
    }

    public function testOrdersReturnedClaimsWithTheOtherFiles(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write('.' . self::THIRD . '.claimed-reader-dead', 'third', time() - 7_200);
        $this->write(self::SECOND, 'second');
        $source = $this->source();

        $names = [$source->claim()?->name, $source->claim()?->name, $source->claim()?->name];

        self::assertSame([self::FIRST, self::SECOND, self::THIRD], $names);
    }

    public function testWorksThroughOneScanBeforeLookingAgain(): void
    {
        $this->write(self::SECOND, 'second');
        $this->write(self::THIRD, 'third');
        $source = $this->source();
        self::assertSame(self::SECOND, $source->claim()?->name);

        $this->write(self::FIRST, 'written later');

        self::assertSame(self::THIRD, $source->claim()?->name);
        self::assertSame(self::FIRST, $source->claim()?->name);
    }

    public function testCreatesTheRejectedDirectoryWithFullPermissionsLeftToTheUmask(): void
    {
        $this->write(self::FIRST, 'first');
        $source = $this->source();
        $file = $source->claim();
        self::assertNotNull($file);
        $umask = umask(0);

        try {
            $source->reject($file);
        } finally {
            umask($umask);
        }

        self::assertSame(0o777, fileperms($this->spool->path . '/rejected') & 0o777);
    }

    public function testKeepsRejectedFilesUpToTheLimitAndOtherFilesThere(): void
    {
        mkdir($this->spool->path . '/rejected');
        $this->write('rejected/README', 'notes');
        $source = $this->source(maxRejectedFiles: 3);
        foreach ([self::FIRST, self::SECOND] as $name) {
            $this->write($name, $name);
            $file = $source->claim();
            self::assertNotNull($file);
            $source->reject($file);
        }

        self::assertSame([self::FIRST, self::SECOND, 'README'], $this->spool->entries('/rejected'));
    }

    public function testAcknowledgeDeletesTheFile(): void
    {
        $this->write(self::FIRST, 'first');
        $source = $this->source();
        $file = $source->claim();
        self::assertNotNull($file);

        $source->acknowledge($file);

        self::assertSame([], $this->spool->entries());
    }

    public function testReleaseReturnsTheFileToTheSpool(): void
    {
        $this->write(self::FIRST, 'first');
        $source = $this->source();
        $file = $source->claim();
        self::assertNotNull($file);

        $source->release($file);

        self::assertSame([self::FIRST], $this->spool->entries());
        self::assertSame('first', $source->claim()?->bytes);
    }

    public function testRejectMovesTheFileAside(): void
    {
        $this->write(self::FIRST, 'first');
        $source = $this->source();
        $file = $source->claim();
        self::assertNotNull($file);

        $source->reject($file);

        self::assertSame(['rejected'], $this->spool->entries());
        self::assertSame([self::FIRST], $this->spool->entries('/rejected'));
        self::assertSame('first', file_get_contents($this->spool->path . '/rejected/' . self::FIRST));
    }

    public function testKeepsOnlyTheNewestRejectedFiles(): void
    {
        mkdir($this->spool->path . '/rejected');
        $this->write('rejected/README', 'notes');
        $source = $this->source(maxRejectedFiles: 2);
        foreach ([self::THIRD, self::FIRST, self::SECOND] as $name) {
            $this->write($name, $name);
            $file = $source->claim();
            self::assertNotNull($file);
            $source->reject($file);
        }

        self::assertSame([self::SECOND, self::THIRD, 'README'], $this->spool->entries('/rejected'));
    }

    public function testDeletesRejectedFilesWhenNoneAreKept(): void
    {
        $this->write(self::FIRST, 'first');
        $source = $this->source(maxRejectedFiles: 0);
        $file = $source->claim();
        self::assertNotNull($file);

        $source->reject($file);

        self::assertSame([], $this->spool->entries());
    }

    public function testDeletesARejectedFileThatCannotBeMovedAside(): void
    {
        $this->write(self::FIRST, 'first');
        // A regular file where the rejected directory should be.
        $this->write('rejected', '');
        $source = $this->source();
        $file = $source->claim();
        self::assertNotNull($file);

        $source->reject($file);

        self::assertSame(['rejected'], $this->spool->entries());
    }

    public function testReturnsAnAbandonedClaimToTheSpool(): void
    {
        $this->write(self::FIRST, 'first');
        $dead = $this->source('reader-dead');
        self::assertNotNull($dead->claim());
        $this->clock->advanceMs(600_001 + 1_000);

        $file = $this->source('reader-alive')->claim();

        self::assertSame(self::FIRST, $file?->name);
        self::assertSame(['.' . self::FIRST . '.claimed-reader-alive'], $this->spool->entries());
    }

    public function testLeavesAnActiveClaimAlone(): void
    {
        $this->write(self::FIRST, 'first');
        self::assertNotNull($this->source('reader-busy')->claim());
        // Exactly the stale-claim age: not abandoned yet.
        $this->clock->advanceMs(600_000);

        self::assertNull($this->source('reader-other')->claim());
        self::assertSame(['.' . self::FIRST . '.claimed-reader-busy'], $this->spool->entries());
    }

    public function testIgnoresClaimMarkersOnOtherFiles(): void
    {
        $this->write('.notes.txt.claimed-reader', 'notes', time() - 7_200);
        $this->write(self::FIRST . '.claimed-reader', 'not hidden', time() - 7_200);

        self::assertNull($this->source()->claim());
        self::assertSame(['.notes.txt.claimed-reader', self::FIRST . '.claimed-reader'], $this->spool->entries());
    }

    public function testDeletesAbandonedTemporaryFiles(): void
    {
        $this->write('.' . self::FIRST . '.tmp', 'crashed writer', time() - 3_700);
        $this->write('.' . self::SECOND . '.tmp', 'busy writer', time() - 3_500);

        $this->source()->claim();

        self::assertSame(['.' . self::SECOND . '.tmp'], $this->spool->entries());
    }

    private function source(string $readerId = 'reader', int $maxRejectedFiles = 100): DirectorySpoolSource
    {
        return new DirectorySpoolSource(new SpoolReaderConfig($this->spool->path, $readerId, maxRejectedFiles: $maxRejectedFiles), $this->clock);
    }

    private function write(string $name, string $bytes, ?int $modified = null): void
    {
        file_put_contents($this->spool->path . '/' . $name, $bytes);
        if (null !== $modified) {
            touch($this->spool->path . '/' . $name, $modified);
        }
    }
}
