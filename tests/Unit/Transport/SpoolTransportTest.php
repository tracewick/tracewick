<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;
use Tracewick\Transport\Payload;
use Tracewick\Transport\SpoolConfig;
use Tracewick\Transport\SpoolTransport;

#[CoversClass(SpoolTransport::class)]
#[UsesClass(SpoolConfig::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Payload::class)]
#[UsesClass(SystemClock::class)]
final class SpoolTransportTest extends TestCase
{
    private TemporaryDirectory $spool;

    protected function setUp(): void
    {
        $this->spool = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->spool->remove();
    }

    public function testWritesTheBodyToAFileNamedByTime(): void
    {
        $transport = new SpoolTransport($this->config(), new ManualClock(1_798_000_000_123_456_789));

        $delivery = $transport->send(new Payload("TWSP\x01profile", 'application/vnd.tracewick.spool', ['ignored' => 'yes']));

        self::assertTrue($delivery->isDelivered());
        $entries = $this->spool->entries();
        self::assertCount(1, $entries);
        self::assertMatchesRegularExpression('/^01798000000123456789-[0-9a-f]{16}\.spool$/D', $entries[0]);
        self::assertSame("TWSP\x01profile", file_get_contents($this->spool->path . '/' . $entries[0]));
    }

    public function testGivesEveryFileItsOwnName(): void
    {
        $transport = new SpoolTransport($this->config(), new ManualClock());

        $transport->send(new Payload('a', 'text/plain'));
        $transport->send(new Payload('b', 'text/plain'));

        self::assertCount(2, $this->spool->entries());
    }

    public function testCreatesAMissingDirectory(): void
    {
        $transport = new SpoolTransport(new SpoolConfig($this->spool->path . '/a/b/', 10, 1_024, 0));

        $delivery = $transport->send(new Payload('profile', 'text/plain'));

        self::assertTrue($delivery->isDelivered());
        self::assertCount(1, $this->spool->entries('/a/b'));
    }

    public function testAcceptsAFileOfExactlyTheMaximumSize(): void
    {
        $transport = new SpoolTransport($this->config());

        self::assertTrue($transport->send(new Payload(str_repeat('x', 1_024), 'text/plain'))->isDelivered());
    }

    public function testRejectsAFileOverTheMaximumSize(): void
    {
        $transport = new SpoolTransport($this->config());

        $delivery = $transport->send(new Payload(str_repeat('x', 1_025), 'text/plain'));

        self::assertSame(DeliveryStatus::Rejected, $delivery->status);
        self::assertSame('The spool file of 1025 bytes exceeds the limit of 1024 bytes.', $delivery->reason);
        self::assertSame([], $this->spool->entries());
    }

    public function testSkipsWhenTheDirectoryHoldsTheMaximumNumberOfFiles(): void
    {
        touch($this->spool->path . '/01798000000000000000-0000000000000000.spool');
        // Neither temporary files nor unrelated files count.
        touch($this->spool->path . '/.01798000000000000001-0000000000000000.spool.tmp');
        touch($this->spool->path . '/README');
        $transport = new SpoolTransport($this->config(maxFiles: 2));

        $first = $transport->send(new Payload('second', 'text/plain'));
        $second = $transport->send(new Payload('third', 'text/plain'));

        self::assertTrue($first->isDelivered());
        self::assertSame(DeliveryStatus::Skipped, $second->status);
        self::assertSame('The spool directory already holds 2 files.', $second->reason);
        self::assertCount(4, $this->spool->entries());
    }

    public function testSkipsWhenTooLittleDiskSpaceWouldRemain(): void
    {
        $transport = new SpoolTransport(new SpoolConfig($this->spool->path, 10, 1_024, \PHP_INT_MAX));

        $delivery = $transport->send(new Payload('profile', 'text/plain'));

        self::assertSame(DeliveryStatus::Skipped, $delivery->status);
        self::assertStringStartsWith('Writing would leave less than', $delivery->reason);
        self::assertSame([], $this->spool->entries());
    }

    public function testFailsWhenTheDirectoryCannotBeCreated(): void
    {
        touch($this->spool->path . '/file');
        $transport = new SpoolTransport(new SpoolConfig($this->spool->path . '/file/spool', 10, 1_024, 0));

        $delivery = $transport->send(new Payload('profile', 'text/plain'));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertSame(\sprintf('Cannot create the spool directory "%s/file/spool".', $this->spool->path), $delivery->reason);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'spool file' => ['01798000000000000000-0123456789abcdef.spool', true];
        yield 'any name ending in .spool' => ['profile.spool', true];
        yield 'temporary file' => ['.01798000000000000000-0123456789abcdef.spool.tmp', false];
        yield 'hidden spool file' => ['.profile.spool', false];
        yield 'other suffix' => ['profile.pb', false];
        yield 'current directory' => ['.', false];
    }

    #[DataProvider('names')]
    public function testRecognisesSpoolFiles(string $name, bool $expected): void
    {
        self::assertSame($expected, SpoolTransport::isSpoolFile($name));
    }

    private function config(int $maxFiles = 10): SpoolConfig
    {
        return new SpoolConfig($this->spool->path, $maxFiles, 1_024, 0);
    }
}
