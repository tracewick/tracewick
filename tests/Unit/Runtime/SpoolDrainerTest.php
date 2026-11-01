<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Encoder\CompactStacksCodec;
use Tracewick\Encoder\ProfileParts;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\ProfileMerger;
use Tracewick\Profile\Stack;
use Tracewick\Runtime\DrainOutcome;
use Tracewick\Runtime\DrainResult;
use Tracewick\Runtime\SpoolAggregationWindow;
use Tracewick\Runtime\SpoolDrainer;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sink\SpoolAggregationKey;
use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolFileException;
use Tracewick\Sink\SpoolFileName;
use Tracewick\Tests\Support\ManualClock;
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
#[UsesClass(CompactStacksCodec::class)]
#[UsesClass(ProfileParts::class)]
#[UsesClass(Profile::class)]
#[UsesClass(ProfileMerger::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(SpoolAggregationKey::class)]
#[UsesClass(SpoolFileName::class)]
#[UsesClass(SpoolAggregationWindow::class)]
#[UsesClass(ManualClock::class)]
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

    public function testRejectsAFileWhenTheBackendReportsAConfigurationError(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $forwarder = new ScriptedForwarder(Delivery::configuration('HTTP 401'), Delivery::delivered());

        $result = $this->drainer($forwarder)->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(1, $result->delivered);
        self::assertSame(1, $result->rejected);
        self::assertSame(self::FIRST . ': HTTP 401', $result->lastRejection);
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

            public function acknowledge(ClaimedSpoolFile $file): bool
            {
                return true;
            }

            public function release(ClaimedSpoolFile $file): void {}

            public function reject(ClaimedSpoolFile $file): void {}
        };

        $result = (new SpoolDrainer($source, new ScriptedForwarder()))->drain();

        self::assertSame(DrainOutcome::Failed, $result->outcome);
        self::assertSame('The store is down.', $result->failure);
    }

    public function testCountsAcknowledgeFailuresAfterASuccessfulSend(): void
    {
        $this->write(self::FIRST, 'first');
        $source = new class ($this->spool->path) implements SpoolSource {
            private ?ClaimedSpoolFile $claimed = null;

            public function __construct(private readonly string $directory) {}

            public function claim(): ?ClaimedSpoolFile
            {
                if ($this->claimed instanceof ClaimedSpoolFile) {
                    return null;
                }

                $name = '01798000000000000001-0000000000000000.spool';
                $path = $this->directory . '/' . $name;
                $bytes = (string) file_get_contents($path);
                $this->claimed = new ClaimedSpoolFile($name, $bytes, $path);

                return $this->claimed;
            }

            public function acknowledge(ClaimedSpoolFile $file): bool
            {
                return false;
            }

            public function release(ClaimedSpoolFile $file): void {}

            public function reject(ClaimedSpoolFile $file): void {}
        };

        $result = (new SpoolDrainer($source, new ScriptedForwarder(Delivery::delivered())))->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(1, $result->delivered);
        self::assertSame(1, $result->ackFailures);
        self::assertSame([self::FIRST], $this->spool->entries());
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

    public function testMergesCompactStacksFilesInAClosedWindow(): void
    {
        $hash = SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 10_000_000);
        $this->writeCompact('01798000000000000001-' . $hash . '-0000000000000001.spool', 1_000_000_000, 1_100_000_000, 2);
        $this->writeCompact('01798000000000000002-' . $hash . '-0000000000000002.spool', 1_200_000_000, 1_300_000_000, 3);
        $forwarder = new ScriptedForwarder(Delivery::delivered());
        $clock = new ManualClock(20_000_000_000);

        $result = $this->drainer($forwarder, new SpoolAggregationWindow(10), $clock)->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(2, $result->delivered);
        self::assertCount(1, $forwarder->forwarded);
        $merged = SpoolFile::fromBytes($forwarder->forwarded[0]->toBytes());
        $parts = (new CompactStacksCodec())->decode($merged->payload);
        self::assertSame(1_000_000_000, $merged->startNs);
        self::assertSame(1_300_000_000, $merged->endNs);
        self::assertNotSame([], $parts->stacks);
        self::assertSame(5, $parts->stacks[0]->count);
        self::assertSame([], $this->spool->entries());
    }

    public function testLeavesAnOpenAggregationWindowInTheSpool(): void
    {
        $hash = SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 10_000_000);
        $this->writeCompact('01798000000000000001-' . $hash . '-0000000000000001.spool', 10_000_000_000, 10_100_000_000, 2);
        $forwarder = new ScriptedForwarder();
        $clock = new ManualClock(10_500_000_000);

        $result = $this->drainer($forwarder, new SpoolAggregationWindow(10), $clock)->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(0, $result->delivered);
        self::assertSame([], $forwarder->forwarded);
        self::assertCount(1, $this->spool->entries());
    }

    public function testFlushesAnOpenWindowWhenAsked(): void
    {
        $hash = SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 10_000_000);
        $this->writeCompact('01798000000000000001-' . $hash . '-0000000000000001.spool', 10_000_000_000, 10_100_000_000, 2);
        $forwarder = new ScriptedForwarder(Delivery::delivered());
        $clock = new ManualClock(10_500_000_000);

        $result = $this->drainer($forwarder, new SpoolAggregationWindow(10), $clock)->drain(includeOpenWindows: true);

        self::assertSame(1, $result->delivered);
        self::assertCount(1, $forwarder->forwarded);
    }

    public function testRejectsCompactStacksFilesWithAnInvalidPayload(): void
    {
        $hash = SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 10_000_000);
        $name = '01798000000000000001-' . $hash . '-0000000000000001.spool';
        $file = new SpoolFile(
            'blog',
            EventType::Wall,
            10_000_000,
            1_000_000_000,
            1_100_000_000,
            ['env' => 'prod'],
            SpoolFile::FORMAT_COMPACT_STACKS,
            "\x02",
            SpoolFile::VERSION_2,
        );
        file_put_contents($this->spool->path . '/' . $name, $file->toBytes());
        $forwarder = new ScriptedForwarder();

        $result = $this->drainer($forwarder, new SpoolAggregationWindow(10), new ManualClock(20_000_000_000))->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(1, $result->rejected);
        self::assertStringContainsString($name . ':', $result->lastRejection);
        self::assertSame([], $forwarder->forwarded);
        self::assertSame([$name], $this->spool->entries('/rejected'));
    }

    public function testGroupsCompactStacksByFileContentsWhenTheNameHasNoHash(): void
    {
        $this->writeCompact('01798000000000000001-0000000000000001.spool', 1_000_000_000, 1_100_000_000, 2);
        $this->writeCompact('01798000000000000002-0000000000000002.spool', 1_200_000_000, 1_300_000_000, 3);
        $forwarder = new ScriptedForwarder(Delivery::delivered());

        $result = $this->drainer($forwarder, new SpoolAggregationWindow(10), new ManualClock(20_000_000_000))->drain();

        self::assertSame(2, $result->delivered);
        self::assertCount(1, $forwarder->forwarded);
    }

    public function testRejectsAMergedGroupTheBackendRefused(): void
    {
        $hash = SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 10_000_000);
        $first = '01798000000000000001-' . $hash . '-0000000000000001.spool';
        $second = '01798000000000000002-' . $hash . '-0000000000000002.spool';
        $this->writeCompact($first, 1_000_000_000, 1_100_000_000, 2);
        $this->writeCompact($second, 1_200_000_000, 1_300_000_000, 3);
        $forwarder = new ScriptedForwarder(Delivery::rejected('Bad request.'));

        $result = $this->drainer($forwarder, new SpoolAggregationWindow(10), new ManualClock(20_000_000_000))->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(0, $result->delivered);
        self::assertSame(2, $result->rejected);
        self::assertSame($first . ': Bad request.', $result->lastRejection);
        self::assertSame([$first, $second], $this->spool->entries('/rejected'));
    }

    public function testRejectsAMergedGroupWhenTheBackendReportsAConfigurationError(): void
    {
        $hash = SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 10_000_000);
        $name = '01798000000000000001-' . $hash . '-0000000000000001.spool';
        $this->writeCompact($name, 1_000_000_000, 1_100_000_000, 2);
        $forwarder = new ScriptedForwarder(Delivery::configuration('HTTP 401'));

        $result = $this->drainer($forwarder, new SpoolAggregationWindow(10), new ManualClock(20_000_000_000))->drain();

        self::assertSame(1, $result->rejected);
        self::assertSame($name . ': HTTP 401', $result->lastRejection);
        self::assertSame([$name], $this->spool->entries('/rejected'));
    }

    public function testEndsThePassWhenAMergedGroupFailsToSend(): void
    {
        $hash = SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 10_000_000);
        $first = '01798000000000000001-' . $hash . '-0000000000000001.spool';
        $second = '01798000000000000002-' . $hash . '-0000000000000002.spool';
        $this->writeCompact($first, 1_000_000_000, 1_100_000_000, 2);
        $this->writeCompact($second, 1_200_000_000, 1_300_000_000, 3);
        $forwarder = new ScriptedForwarder(Delivery::failed('Timeout.'));

        $result = $this->drainer($forwarder, new SpoolAggregationWindow(10), new ManualClock(20_000_000_000))->drain();

        self::assertSame(DrainOutcome::Failed, $result->outcome);
        self::assertSame('Timeout.', $result->failure);
        self::assertSame([$first, $second], $this->spool->entries());
    }

    public function testCountsAcknowledgeFailuresForAMergedGroup(): void
    {
        $hash = SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 10_000_000);
        $first = '01798000000000000001-' . $hash . '-0000000000000001.spool';
        $this->writeCompact($first, 1_000_000_000, 1_100_000_000, 2);
        $source = new class ($this->spool->path) implements SpoolSource {
            private ?ClaimedSpoolFile $claimed = null;

            public function __construct(private readonly string $directory) {}

            public function claim(): ?ClaimedSpoolFile
            {
                if ($this->claimed instanceof ClaimedSpoolFile) {
                    return null;
                }

                $entries = scandir($this->directory);
                if (false === $entries) {
                    return null;
                }

                foreach ($entries as $name) {
                    if (!str_ends_with($name, '.spool')) {
                        continue;
                    }

                    $path = $this->directory . '/' . $name;
                    $this->claimed = new ClaimedSpoolFile($name, (string) file_get_contents($path), $path);

                    return $this->claimed;
                }

                return null;
            }

            public function acknowledge(ClaimedSpoolFile $file): bool
            {
                return false;
            }

            public function release(ClaimedSpoolFile $file): void {}

            public function reject(ClaimedSpoolFile $file): void {}
        };

        $result = (new SpoolDrainer(
            $source,
            new ScriptedForwarder(Delivery::delivered()),
            new SpoolAggregationWindow(10),
            new ManualClock(20_000_000_000),
        ))->drain();

        self::assertSame(1, $result->delivered);
        self::assertSame(1, $result->ackFailures);
    }

    public function testTreatsAMergeFailureAsAFailedDelivery(): void
    {
        $hash = '0123456789abcdef';
        $this->writeCompact(
            '01798000000000000001-' . $hash . '-0000000000000001.spool',
            1_000_000_000,
            1_100_000_000,
            2,
            EventType::Wall,
            10_000_000,
        );
        $this->writeCompact(
            '01798000000000000002-' . $hash . '-0000000000000002.spool',
            1_200_000_000,
            1_300_000_000,
            3,
            EventType::Cpu,
            10_000_000,
        );
        $forwarder = new ScriptedForwarder();

        $result = $this->drainer($forwarder, new SpoolAggregationWindow(10), new ManualClock(20_000_000_000))->drain();

        self::assertSame(DrainOutcome::Failed, $result->outcome);
        self::assertStringContainsString('event type', $result->failure);
        self::assertSame([], $forwarder->forwarded);
        self::assertCount(2, $this->spool->entries());
    }

    private function drainer(
        ScriptedForwarder $forwarder,
        SpoolAggregationWindow $window = new SpoolAggregationWindow(),
        ?ManualClock $clock = null,
    ): SpoolDrainer {
        return new SpoolDrainer(
            new DirectorySpoolSource(new SpoolReaderConfig($this->spool->path, 'drainer')),
            $forwarder,
            $window,
            $clock ?? new SystemClock(),
        );
    }

    private function write(string $name, string $payload): void
    {
        $file = new SpoolFile('blog', EventType::Wall, 10_000_000, 1_798_000_000_000_000_000, 1_798_000_000_100_000_000, [], SpoolFile::FORMAT_PPROF, $payload);
        file_put_contents($this->spool->path . '/' . $name, $file->toBytes());
    }

    private function writeCompact(
        string $name,
        int $startNs,
        int $endNs,
        int $weight,
        EventType $eventType = EventType::Wall,
        int $periodNs = 10_000_000,
    ): void {
        $profile = new Profile($eventType, $periodNs, $startNs, $endNs, [new Frame('work')], [new Stack([0], $weight)], new Labels(['env' => 'prod']));
        $file = new SpoolFile(
            'blog',
            $eventType,
            $periodNs,
            $startNs,
            $endNs,
            ['env' => 'prod'],
            SpoolFile::FORMAT_COMPACT_STACKS,
            (new CompactStacksCodec())->encode($profile),
            SpoolFile::VERSION_2,
        );
        file_put_contents($this->spool->path . '/' . $name, $file->toBytes());
    }
}
