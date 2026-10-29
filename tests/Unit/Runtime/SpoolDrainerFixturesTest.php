<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Encoder\Pprof\LabelKeyRewriter;
use Tracewick\Encoder\Pprof\ProtobufReader;
use Tracewick\Encoder\PprofEncoder;
use Tracewick\Runtime\DrainOutcome;
use Tracewick\Runtime\DrainResult;
use Tracewick\Runtime\SpoolDrainer;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Sink\PyroscopeSink;
use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolFileException;
use Tracewick\Tests\Support\ScriptedTransport;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\ClaimedSpoolFile;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DirectorySpoolSource;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\Payload;
use Tracewick\Transport\SpoolReaderConfig;
use Tracewick\Transport\SpoolTransport;

/**
 * Drains a copy of the spool fixtures in tests/Fixtures/spool/v1 into Pyroscope and checks the
 * results their README requires of every reader.
 */
#[CoversClass(SpoolDrainer::class)]
#[UsesClass(DrainResult::class)]
#[UsesClass(DrainOutcome::class)]
#[UsesClass(DirectorySpoolSource::class)]
#[UsesClass(ClaimedSpoolFile::class)]
#[UsesClass(SpoolReaderConfig::class)]
#[UsesClass(SpoolTransport::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(SpoolFile::class)]
#[UsesClass(SpoolFileException::class)]
#[UsesClass(PyroscopeSink::class)]
#[UsesClass(PyroscopeConfig::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(HttpConfig::class)]
#[UsesClass(CircuitBreakerConfig::class)]
#[UsesClass(PprofEncoder::class)]
#[UsesClass(LabelKeyRewriter::class)]
#[UsesClass(ProtobufReader::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Payload::class)]
final class SpoolDrainerFixturesTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/spool/v1';

    private TemporaryDirectory $spool;

    protected function setUp(): void
    {
        $this->spool = new TemporaryDirectory();
        foreach (glob(self::FIXTURES . '/*.spool') ?: [] as $fixture) {
            copy($fixture, $this->spool->path . '/' . basename($fixture));
        }
    }

    protected function tearDown(): void
    {
        $this->spool->remove();
    }

    public function testDeliversTheValidFixturesAndRejectsTheRest(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered(), Delivery::delivered(), Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);
        $source = new DirectorySpoolSource(new SpoolReaderConfig($this->spool->path, 'drainer'));

        $result = (new SpoolDrainer($source, $sink))->drain();

        self::assertSame(DrainOutcome::Empty, $result->outcome);
        self::assertSame(3, $result->delivered);
        self::assertSame(8, $result->rejected);
        self::assertSame(['blog', 'blog', 'blog'], array_map(static fn(Payload $payload): ?string => $payload->parameters['name'] ?? null, $transport->sent));
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
        ], $this->spool->entries('/rejected'));
    }
}
