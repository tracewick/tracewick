<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Encoder\CompactStacksCodec;
use Tracewick\Encoder\Pprof\ProtobufWriter;
use Tracewick\Encoder\Pprof\StringTable;
use Tracewick\Encoder\PprofEncoder;
use Tracewick\Encoder\ProfileParts;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\ProfileMerger;
use Tracewick\Profile\Stack;
use Tracewick\Runtime\DrainOutcome;
use Tracewick\Runtime\DrainResult;
use Tracewick\Runtime\SpoolAggregationWindow;
use Tracewick\Runtime\SpoolDrainer;
use Tracewick\Sampler\Frame;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Sink\PyroscopePushRequest;
use Tracewick\Sink\PyroscopeSink;
use Tracewick\Sink\SpoolAggregationKey;
use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolFileException;
use Tracewick\Sink\SpoolFileName;
use Tracewick\Tests\Support\ProtobufMessage;
use Tracewick\Tests\Support\ScriptedTransport;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\CachingDnsResolver;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\ClaimedSpoolFile;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DirectorySpoolSource;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\Payload;
use Tracewick\Transport\SpoolReaderConfig;
use Tracewick\Transport\SpoolTransport;
use Tracewick\Transport\SystemDnsResolver;

/**
 * Drains a copy of the spool fixtures in tests/Fixtures/spool/v2 into Pyroscope and checks the
 * results their README requires of every reader.
 */
#[CoversClass(SpoolDrainer::class)]
#[UsesClass(DrainResult::class)]
#[UsesClass(DrainOutcome::class)]
#[UsesClass(SpoolAggregationWindow::class)]
#[UsesClass(DirectorySpoolSource::class)]
#[UsesClass(ClaimedSpoolFile::class)]
#[UsesClass(SpoolReaderConfig::class)]
#[UsesClass(SpoolTransport::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(SpoolFile::class)]
#[UsesClass(SpoolFileException::class)]
#[UsesClass(PyroscopeSink::class)]
#[UsesClass(PyroscopeConfig::class)]
#[UsesClass(PyroscopePushRequest::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(HttpConfig::class)]
#[UsesClass(CircuitBreakerConfig::class)]
#[UsesClass(PprofEncoder::class)]
#[UsesClass(ProtobufWriter::class)]
#[UsesClass(StringTable::class)]
#[UsesClass(CompactStacksCodec::class)]
#[UsesClass(ProfileParts::class)]
#[UsesClass(Profile::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(ProfileMerger::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(SpoolAggregationKey::class)]
#[UsesClass(SpoolFileName::class)]
#[UsesClass(CachingDnsResolver::class)]
#[UsesClass(SystemDnsResolver::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Payload::class)]
final class SpoolDrainerFixturesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/spool/v2';

    private TemporaryDirectory $spool;

    protected function setUp(): void
    {
        $this->spool = new TemporaryDirectory();
        $fixtures = glob(self::FIXTURES . '/*.spool');
        self::assertNotFalse($fixtures);
        foreach ($fixtures as $fixture) {
            copy($fixture, $this->spool->path . '/' . basename($fixture));
        }
    }

    protected function tearDown(): void
    {
        $this->spool->remove();
    }

    public function testDeliversTheValidFixturesAndRejectsTheRest(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered(), Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);
        $source = new DirectorySpoolSource(new SpoolReaderConfig($this->spool->path, 'drainer'));

        $result = (new SpoolDrainer($source, $sink, new SpoolAggregationWindow(10)))->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        // web-request + unknown-header-key share an aggregation key and merge; edge-cases is alone.
        self::assertSame(3, $result->delivered);
        self::assertSame(9, $result->rejected);
        self::assertCount(2, $transport->sent);
        self::assertSame(['blog', 'blog'], array_map(
            static function (Payload $payload): string {
                $series = ProtobufMessage::decode($payload->body)->messages(1)[0] ?? null;
                self::assertNotNull($series);
                foreach ($series->messages(1) as $label) {
                    if ('service_name' === $label->string(1)) {
                        return $label->string(2);
                    }
                }

                self::fail('Push request has no service_name label.');
            },
            $transport->sent,
        ));
        self::assertSame(['rejected'], $this->spool->entries());
        self::assertSame([
            'bad-magic.spool',
            'end-before-start.spool',
            'header-not-json.spool',
            'header-too-large.spool',
            'missing-key.spool',
            'truncated-payload.spool',
            'unknown-format.spool',
            'unsupported-version.spool',
            'version-1.spool',
        ], $this->spool->entries('/rejected'));
    }
}
