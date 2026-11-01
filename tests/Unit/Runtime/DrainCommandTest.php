<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Encoder\CompactStacksCodec;
use Tracewick\Encoder\PprofEncoder;
use Tracewick\Encoder\ProfileParts;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\ProfileMerger;
use Tracewick\Profile\Stack;
use Tracewick\Runtime\DrainCommand;
use Tracewick\Runtime\DrainOutcome;
use Tracewick\Runtime\DrainResult;
use Tracewick\Runtime\DrainSettings;
use Tracewick\Runtime\SpoolAggregationWindow;
use Tracewick\Runtime\SpoolDrainer;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Sink\PyroscopePushRequest;
use Tracewick\Sink\PyroscopeSink;
use Tracewick\Sink\SpoolAggregationKey;
use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolFileException;
use Tracewick\Sink\SpoolFileName;
use Tracewick\Sink\SpoolForwarder;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\ScriptedForwarder;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\CachingDnsResolver;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\CircuitBreakerTransport;
use Tracewick\Transport\ClaimedSpoolFile;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DirectorySpoolSource;
use Tracewick\Transport\FallbackCircuitBreakerStore;
use Tracewick\Transport\FileCircuitBreakerStore;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\HttpTransport;
use Tracewick\Transport\InMemoryCircuitBreakerStore;
use Tracewick\Transport\SpoolReaderConfig;
use Tracewick\Transport\SpoolTransport;
use Tracewick\Transport\SystemDnsResolver;

#[CoversClass(DrainCommand::class)]
#[UsesClass(DrainSettings::class)]
#[UsesClass(SpoolDrainer::class)]
#[UsesClass(SpoolAggregationWindow::class)]
#[UsesClass(DrainResult::class)]
#[UsesClass(DrainOutcome::class)]
#[UsesClass(DirectorySpoolSource::class)]
#[UsesClass(ClaimedSpoolFile::class)]
#[UsesClass(SpoolReaderConfig::class)]
#[UsesClass(SpoolTransport::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(SpoolFile::class)]
#[UsesClass(SpoolFileException::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(PyroscopeConfig::class)]
#[UsesClass(PyroscopeSink::class)]
#[UsesClass(PyroscopePushRequest::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(HttpConfig::class)]
#[UsesClass(HttpTransport::class)]
#[UsesClass(CachingDnsResolver::class)]
#[UsesClass(SystemDnsResolver::class)]
#[UsesClass(CircuitBreakerConfig::class)]
#[UsesClass(CircuitBreakerTransport::class)]
#[UsesClass(CircuitBreakerState::class)]
#[UsesClass(FileCircuitBreakerStore::class)]
#[UsesClass(FallbackCircuitBreakerStore::class)]
#[UsesClass(InMemoryCircuitBreakerStore::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(PprofEncoder::class)]
#[UsesClass(CompactStacksCodec::class)]
#[UsesClass(ProfileParts::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Profile::class)]
#[UsesClass(ProfileMerger::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Frame::class)]
#[UsesClass(SpoolAggregationKey::class)]
#[UsesClass(SpoolFileName::class)]
final class DrainCommandTest extends TestCase
{
    private const FIRST = '01798000000000000001-0000000000000000.spool';

    private const SECOND = '01798000000000000002-0000000000000000.spool';

    private const THIRD = '01798000000000000003-0000000000000000.spool';

    private TemporaryDirectory $spool;

    private ManualClock $clock;

    /** @var resource */
    private $output;

    /** @var resource */
    private $errors;

    /** @var list<int> */
    private array $sleeps = [];

    private ?PyroscopeConfig $pyroscope = null;

    private ?DrainCommand $command = null;

    protected function setUp(): void
    {
        $this->spool = new TemporaryDirectory();
        $this->clock = new ManualClock();
        $this->output = $this->memoryStream();
        $this->errors = $this->memoryStream();
    }

    protected function tearDown(): void
    {
        $this->spool->remove();
    }

    public function testPrintsTheHelp(): void
    {
        $exitCode = $this->command(new ScriptedForwarder())->run(['--help']);

        self::assertSame(0, $exitCode);
        self::assertStringStartsWith('Usage: tracewick-drain --spool-dir=PATH --pyroscope-url=URL [options]', $this->read($this->output));
        self::assertSame('', $this->read($this->errors));
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'missing spool directory' => [['--pyroscope-url=http://pyroscope:4040'], 'The spool directory is required.'];
        yield 'positional argument' => [['drain'], 'Unexpected argument "drain".'];
        yield 'argument containing an option' => [['x--once'], 'Unexpected argument "x--once".'];
        yield 'option followed by other text' => [['--once!'], 'Unexpected argument "--once!".'];
        yield 'unknown option' => [['--verbose'], 'Unknown option "--verbose".'];
        yield 'flag with a value' => [['--once=yes'], 'Unknown option "--once=yes".'];
        yield 'option without a value' => [['--limit'], 'Unknown option "--limit".'];
        yield 'invalid limit' => [['--spool-dir=/tmp', '--pyroscope-url=http://pyroscope:4040', '--limit=0'], '--limit must be a whole number from 1 to'];
        yield 'value with a line break' => [['--spool-dir=/tmp', '--pyroscope-url=http://pyroscope:4040', "--sleep=1\n2"], '--sleep must be a whole number from 0 to 3600'];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testRejectsInvalidArguments(array $arguments, string $message): void
    {
        $exitCode = $this->command(new ScriptedForwarder())->run($arguments);

        self::assertSame(DrainCommand::EXIT_USAGE, $exitCode);
        self::assertStringStartsWith('tracewick-drain: ' . $message, $this->read($this->errors));
        self::assertStringEndsWith("\nRun tracewick-drain --help for the options.\n", $this->read($this->errors));
        self::assertSame('', $this->read($this->output));
    }

    public function testDeliversTheSpoolOnceAndExits(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $forwarder = new ScriptedForwarder(Delivery::delivered(), Delivery::rejected('Bad request.'));

        $exitCode = $this->command($forwarder)->run(['--once', ...$this->required()]);

        self::assertSame(0, $exitCode);
        self::assertSame(['first', 'second'], array_map(static fn(SpoolFile $file): string => $file->labels['id'] ?? '', $forwarder->forwarded));
        self::assertSame("Delivered 1, rejected 1 spool files.\n", $this->read($this->output));
        self::assertSame('Rejected ' . self::SECOND . ": Bad request.\n", $this->read($this->errors));
        self::assertSame([], $this->sleeps);
    }

    public function testFailsAPassWithOnceWhenTheBackendFails(): void
    {
        $this->write(self::FIRST, 'first');
        $forwarder = new ScriptedForwarder(Delivery::failed('Connection refused.'));

        $exitCode = $this->command($forwarder)->run(['--once', ...$this->required()]);

        self::assertSame(DrainCommand::EXIT_FAILURE, $exitCode);
        self::assertSame("Delivery failed: Connection refused.\n", $this->read($this->errors));
        self::assertSame("Delivered 0, rejected 0 spool files.\n", $this->read($this->output));
        self::assertSame([self::FIRST], $this->spool->entries());
        self::assertSame([], $this->sleeps);
    }

    public function testSleepsWhileTheSpoolIsEmptyUntilStopped(): void
    {
        $command = $this->command(new ScriptedForwarder(), stopAfterSleeps: 2);

        $exitCode = $command->run([...$this->required(), '--sleep=5']);

        self::assertSame(0, $exitCode);
        self::assertSame([5_000, 5_000], $this->sleeps);
        self::assertSame("Delivered 0, rejected 0 spool files.\n", $this->read($this->output));
    }

    public function testDeliversFilesThatArriveWhileRunning(): void
    {
        $forwarder = new ScriptedForwarder(Delivery::delivered());
        $command = $this->command($forwarder, stopAfterSleeps: 2, onSleep: function (int $sleeps): void {
            if (1 === $sleeps) {
                $this->write(self::FIRST, 'first');
            }
        });

        $command->run($this->required());

        self::assertSame(['first'], array_map(static fn(SpoolFile $file): string => $file->labels['id'] ?? '', $forwarder->forwarded));
        self::assertSame([1_000, 1_000], $this->sleeps);
    }

    public function testBacksOffWhileTheBackendFailsAndResetsAfterASuccess(): void
    {
        $this->write(self::FIRST, 'first');
        $failures = array_fill(0, 7, Delivery::failed('Timeout.'));
        $forwarder = new ScriptedForwarder(...[...$failures, Delivery::delivered(), Delivery::failed('Timeout.')]);
        $command = $this->command($forwarder, stopAfterSleeps: 9, onSleep: function (int $sleeps): void {
            if (8 === $sleeps) {
                $this->write(self::SECOND, 'second');
            }
        });

        $command->run($this->required());

        self::assertSame([1_000, 2_000, 4_000, 8_000, 16_000, 30_000, 30_000, 1_000, 1_000], $this->sleeps);
        self::assertSame(str_repeat("Delivery failed: Timeout.\n", 8), $this->read($this->errors));
        self::assertSame([self::SECOND], $this->spool->entries());
    }

    public function testExitsAfterTheFileLimit(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $this->write(self::THIRD, 'third');
        $forwarder = new ScriptedForwarder(Delivery::delivered(), Delivery::rejected('Bad request.'));

        $exitCode = $this->command($forwarder)->run([...$this->required(), '--limit=2']);

        self::assertSame(0, $exitCode);
        self::assertSame("Delivered 1, rejected 1 spool files.\n", $this->read($this->output));
        self::assertSame([self::THIRD, 'rejected'], $this->spool->entries());
    }

    public function testExitsAboveTheMemoryLimit(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $usage = [1_024, 1_025];
        $command = new DrainCommand(
            $this->output,
            $this->errors,
            $this->clock,
            $this->recordSleep(...),
            static function () use (&$usage): int {
                return array_shift($usage) ?? self::fail('Memory was checked too often.');
            },
            static fn(): SpoolForwarder => new ScriptedForwarder(Delivery::delivered()),
        );

        $exitCode = $command->run([...$this->required(), '--memory-limit=1K']);

        self::assertSame(0, $exitCode);
        self::assertSame("Delivered 1, rejected 0 spool files.\n", $this->read($this->output));
        self::assertSame([self::SECOND], $this->spool->entries());
    }

    public function testMeasuresTheMemoryOfTheProcess(): void
    {
        $this->write(self::FIRST, 'first');
        $command = new DrainCommand($this->output, $this->errors, $this->clock, $this->recordSleep(...), forwarder: static fn(): SpoolForwarder => new ScriptedForwarder());

        $exitCode = $command->run([...$this->required(), '--memory-limit=1']);

        self::assertSame(0, $exitCode);
        self::assertSame([self::FIRST], $this->spool->entries());
    }

    public function testExitsWhenTheTimeLimitIsReached(): void
    {
        $command = $this->command(new ScriptedForwarder(), onSleep: function (): void {
            $this->clock->advanceMs(1_000);
        });

        $exitCode = $command->run([...$this->required(), '--time-limit=3']);

        self::assertSame(0, $exitCode);
        self::assertSame([1_000, 1_000, 1_000], $this->sleeps);
    }

    public function testRunsUntilTheTimeLimitIsReached(): void
    {
        // Each pause is a nanosecond short of a second, so three pauses stay below the limit.
        $command = $this->command(new ScriptedForwarder(), onSleep: function (): void {
            $this->clock->advanceNs(999_999_999);
        });

        $exitCode = $command->run([...$this->required(), '--time-limit=3']);

        self::assertSame(0, $exitCode);
        self::assertSame([1_000, 1_000, 1_000, 1_000], $this->sleeps);
    }

    public function testStopsAfterTheCurrentFile(): void
    {
        $this->write(self::FIRST, 'first');
        $this->write(self::SECOND, 'second');
        $forwarder = new class implements SpoolForwarder {
            public ?DrainCommand $command = null;

            public function forward(SpoolFile $file): Delivery
            {
                $this->command?->stop();

                return Delivery::delivered();
            }
        };
        $command = $this->command($forwarder);
        $forwarder->command = $command;

        $exitCode = $command->run($this->required());

        self::assertSame(0, $exitCode);
        self::assertSame("Delivered 2, rejected 0 spool files.\n", $this->read($this->output));
        self::assertSame([], $this->spool->entries());
    }

    public function testReadsTheSettingsFromTheEnvironment(): void
    {
        $exitCode = $this->command(new ScriptedForwarder())->run(['--once'], [
            'TRACEWICK_SPOOL_DIR' => $this->spool->path,
            'TRACEWICK_PYROSCOPE_URL' => 'https://pyroscope.example',
            'TRACEWICK_PYROSCOPE_USER' => 'drain',
            'TRACEWICK_PYROSCOPE_PASSWORD' => 'secret',
            'TRACEWICK_PYROSCOPE_TENANT' => 'team-a',
        ]);

        self::assertSame(0, $exitCode);
        $config = $this->pyroscope ?? self::fail('The forwarder was not created.');
        self::assertSame('https://pyroscope.example', $config->serverUrl);
        self::assertSame('drain', $config->basicAuthUser);
        self::assertSame('secret', $config->basicAuthPassword);
        self::assertSame('team-a', $config->tenantId);
    }

    public function testSendsToPyroscopeByDefault(): void
    {
        $command = new DrainCommand($this->output, $this->errors, $this->clock, $this->recordSleep(...));

        $exitCode = $command->run(['--once', ...$this->required()]);

        self::assertSame(0, $exitCode);
        self::assertSame("Delivered 0, rejected 0 spool files.\n", $this->read($this->output));
    }

    public function testDefaultPauseSleepsInShortStepsUntilTheTimeLimit(): void
    {
        $command = new DrainCommand(
            $this->output,
            $this->errors,
            new SystemClock(),
            null,
            static fn(): int => 0,
            static fn(): SpoolForwarder => new ScriptedForwarder(),
        );

        $exitCode = $command->run([...$this->required(), '--sleep=1', '--time-limit=1']);

        self::assertSame(0, $exitCode);
        self::assertSame("Delivered 0, rejected 0 spool files.\n", $this->read($this->output));
    }

    /**
     * @param ?\Closure(int): void $onSleep called with the number of sleeps so far
     */
    private function command(SpoolForwarder $forwarder, ?int $stopAfterSleeps = null, ?\Closure $onSleep = null): DrainCommand
    {
        $this->command = new DrainCommand(
            $this->output,
            $this->errors,
            $this->clock,
            function (int $milliseconds) use ($stopAfterSleeps, $onSleep): void {
                $this->recordSleep($milliseconds);
                if ($onSleep instanceof \Closure) {
                    $onSleep(\count($this->sleeps));
                }

                if (null !== $stopAfterSleeps && \count($this->sleeps) >= $stopAfterSleeps) {
                    $this->command?->stop();
                }
            },
            null,
            function (PyroscopeConfig $config) use ($forwarder): SpoolForwarder {
                $this->pyroscope = $config;

                return $forwarder;
            },
        );

        return $this->command;
    }

    private function recordSleep(int $milliseconds): void
    {
        $this->sleeps[] = $milliseconds;
        if (\count($this->sleeps) > 100) {
            self::fail('The command did not stop.');
        }
    }

    /**
     * @return list<string>
     */
    private function required(): array
    {
        return ['--spool-dir=' . $this->spool->path, '--pyroscope-url=http://pyroscope:4040', '--reader-id=drain'];
    }

    private function write(string $name, string $id): void
    {
        $profile = new Profile(
            EventType::Wall,
            10_000_000,
            1_000_000_000,
            1_100_000_000,
            [new Frame('work')],
            [new Stack([0], 1)],
            new Labels(['id' => $id]),
        );
        $file = new SpoolFile(
            'blog',
            EventType::Wall,
            10_000_000,
            1_000_000_000,
            1_100_000_000,
            ['id' => $id],
            SpoolFile::FORMAT_COMPACT_STACKS,
            (new CompactStacksCodec())->encode($profile),
            SpoolFile::VERSION_2,
        );
        file_put_contents($this->spool->path . '/' . $name, $file->toBytes());
    }

    /**
     * @return resource
     */
    private function memoryStream()
    {
        $stream = fopen('php://memory', 'w+');
        if (false === $stream) {
            self::fail('Cannot open a memory stream.');
        }

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
