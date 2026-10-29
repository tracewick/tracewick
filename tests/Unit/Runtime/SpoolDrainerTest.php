<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Runtime\DrainOutcome;
use Tracewick\Runtime\DrainResult;
use Tracewick\Runtime\SpoolDrainer;
use Tracewick\Sampler\EventType;
use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolFileException;
use Tracewick\Tests\Support\ScriptedForwarder;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\ClaimedSpoolFile;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DirectorySpoolSource;
use Tracewick\Transport\SpoolReaderConfig;
use Tracewick\Transport\SpoolSource;
use Tracewick\Transport\SpoolTransport;

#[CoversClass(SpoolDrainer::class)]
#[CoversClass(DrainResult::class)]
#[CoversClass(DrainOutcome::class)]
#[UsesClass(DirectorySpoolSource::class)]
#[UsesClass(ClaimedSpoolFile::class)]
#[UsesClass(SpoolReaderConfig::class)]
#[UsesClass(SpoolTransport::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(SpoolFile::class)]
#[UsesClass(SpoolFileException::class)]
#[UsesClass(Delivery::class)]
final class SpoolDrainerTest extends TestCase
{
    private const FIRST = '01798000000000000001-0000000000000000.spool';

    private const SECOND = '01798000000000000002-0000000000000000.spool';

    private const THIRD = '01798000000000000003-0000000000000000.spool';

    private TemporaryDirectory $spool;

    protected function setUp(): void
    {
        $this->spool = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->spool->remove();
    }

    public function testReportsAnEmptySpool(): void
    {
        $result = $this->drainer(new ScriptedForwarder())->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(0, $result->delivered);
        self::assertSame(0, $result->rejected);
        self::assertSame('', $result->lastRejection);
        self::assertSame('', $result->failure);
    }

    public function testDeliversEveryFileOldestFirstAndRemovesIt(): void
    {
        $this->write(self::SECOND, 'second');
        $this->write(self::FIRST, 'first');
        $forwarder = new ScriptedForwarder(Delivery::delivered(), Delivery::delivered());

        $result = $this->drainer($forwarder)->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(2, $result->delivered);
        self::assertSame(['first', 'second'], $forwarder->payloads());
        self::assertSame([], $this->spool->entries());
    }

    public function testRejectsAnInvalidFileWithoutForwardingItAndGoesOn(): void
    {
        file_put_contents($this->spool->path . '/' . self::FIRST, 'not a spool file');
        $this->write(self::SECOND, 'second');
        $forwarder = new ScriptedForwarder(Delivery::delivered());

        $result = $this->drainer($forwarder)->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(1, $result->delivered);
        self::assertSame(1, $result->rejected);
        self::assertSame(self::FIRST . ': The file does not start with the spool magic bytes.', $result->lastRejection);
        self::assertSame(['second'], $forwarder->payloads());
        self::assertSame([self::FIRST], $this->spool->entries('/rejected'));
    }

    public function testRejectsAFileTheBackendRefused(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $forwarder = new ScriptedForwarder(Delivery::rejected('Bad request.'), Delivery::delivered());

        $result = $this->drainer($forwarder)->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(1, $result->delivered);
        self::assertSame(1, $result->rejected);
        self::assertSame(self::FIRST . ': Bad request.', $result->lastRejection);
        self::assertSame(['rejected'], $this->spool->entries());
        self::assertSame([self::FIRST], $this->spool->entries('/rejected'));
    }

    public function testEndsThePassAtAFailedDeliveryAndKeepsTheFile(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $this->write(self::THIRD, 'third');
        $forwarder = new ScriptedForwarder(Delivery::delivered(), Delivery::failed('Connection refused.'));

        $result = $this->drainer($forwarder)->drain();

        self::assertSame(DrainOutcome::Failed, $result->outcome);
        self::assertSame(1, $result->delivered);
        self::assertSame('Connection refused.', $result->failure);
        self::assertSame(['first', 'second'], $forwarder->payloads());
        self::assertSame([self::SECOND, self::THIRD], $this->spool->entries());
    }

    public function testEndsThePassAtASkippedDelivery(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $forwarder = new ScriptedForwarder(Delivery::skipped('Circuit breaker is open.'));

        $result = $this->drainer($forwarder)->drain();

        self::assertSame(DrainOutcome::Failed, $result->outcome);
        self::assertSame(0, $result->delivered);
        self::assertSame('Circuit breaker is open.', $result->failure);
        self::assertSame([self::FIRST, self::SECOND], $this->spool->entries());
    }

    public function testTreatsAThrowingForwarderAsAFailedDelivery(): void
    {
        $this->write(self::FIRST, 'first');
        $forwarder = new ScriptedForwarder(new \RuntimeException('Boom.'));

        $result = $this->drainer($forwarder)->drain();

        self::assertSame(DrainOutcome::Failed, $result->outcome);
        self::assertSame('Boom.', $result->failure);
        self::assertSame([self::FIRST], $this->spool->entries());
    }

    public function testStopsAfterTheGivenNumberOfFiles(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $this->write(self::THIRD, 'third');
        $forwarder = new ScriptedForwarder(Delivery::delivered(), Delivery::rejected('Bad request.'));

        $result = $this->drainer($forwarder)->drain(2);

        self::assertSame(DrainOutcome::LimitReached, $result->outcome);
        self::assertSame(1, $result->delivered);
        self::assertSame(1, $result->rejected);
        self::assertSame(self::SECOND . ': Bad request.', $result->lastRejection);
        self::assertSame([self::THIRD, 'rejected'], $this->spool->entries());
    }

    public function testClaimsNothingWhenNoFileIsAllowed(): void
    {
        $this->write(self::FIRST, 'first');

        $result = $this->drainer(new ScriptedForwarder())->drain(0);

        self::assertSame(DrainOutcome::LimitReached, $result->outcome);
        self::assertSame([self::FIRST], $this->spool->entries());
    }

    public function testReportsASourceThatThrowsAsAFailure(): void
    {
        $source = new class implements SpoolSource {
            public function claim(): ?ClaimedSpoolFile
            {
                throw new \RuntimeException('The store is down.');
            }

            public function acknowledge(ClaimedSpoolFile $file): void {}

            public function release(ClaimedSpoolFile $file): void {}

            public function reject(ClaimedSpoolFile $file): void {}
        };

        $result = (new SpoolDrainer($source, new ScriptedForwarder()))->drain();

        self::assertSame(DrainOutcome::Failed, $result->outcome);
        self::assertSame('The store is down.', $result->failure);
    }

    public function testKeepsCountsAndTheLastRejectionWhenThePassFails(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $this->write(self::THIRD, 'third');
        $forwarder = new ScriptedForwarder(Delivery::delivered(), Delivery::rejected('Bad request.'), Delivery::failed('Timeout.'));

        $result = $this->drainer($forwarder)->drain();

        self::assertSame(DrainOutcome::Failed, $result->outcome);
        self::assertSame(1, $result->delivered);
        self::assertSame(1, $result->rejected);
        self::assertSame(self::SECOND . ': Bad request.', $result->lastRejection);
        self::assertSame('Timeout.', $result->failure);
    }

    private function drainer(ScriptedForwarder $forwarder): SpoolDrainer
    {
        return new SpoolDrainer(new DirectorySpoolSource(new SpoolReaderConfig($this->spool->path, 'drainer')), $forwarder);
    }

    private function write(string $name, string $payload): void
    {
        $file = new SpoolFile('blog', EventType::Wall, 10_000_000, 1_798_000_000_000_000_000, 1_798_000_000_100_000_000, [], SpoolFile::FORMAT_PPROF, $payload);
        file_put_contents($this->spool->path . '/' . $name, $file->toBytes());
    }
}
