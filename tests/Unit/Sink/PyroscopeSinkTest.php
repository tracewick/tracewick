<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Encoder\Pprof\LabelKeyRewriter;
use Tracewick\Encoder\Pprof\ProtobufException;
use Tracewick\Encoder\Pprof\ProtobufReader;
use Tracewick\Encoder\Pprof\ProtobufWriter;
use Tracewick\Encoder\Pprof\StringTable;
use Tracewick\Encoder\PprofEncoder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Sink\PyroscopeSink;
use Tracewick\Sink\SpoolFile;
use Tracewick\Tests\Support\ProtobufMessage;
use Tracewick\Tests\Support\ScriptedTransport;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\CircuitBreakerTransport;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\HttpTransport;
use Tracewick\Transport\Payload;

#[CoversClass(PyroscopeSink::class)]
#[UsesClass(PyroscopeConfig::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(HttpConfig::class)]
#[UsesClass(HttpTransport::class)]
#[UsesClass(CircuitBreakerConfig::class)]
#[UsesClass(CircuitBreakerTransport::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Payload::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(PprofEncoder::class)]
#[UsesClass(ProtobufWriter::class)]
#[UsesClass(ProtobufReader::class)]
#[UsesClass(ProtobufException::class)]
#[UsesClass(LabelKeyRewriter::class)]
#[UsesClass(SpoolFile::class)]
#[UsesClass(StringTable::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
final class PyroscopeSinkTest extends TestCase
{
    private const START_NS = 1_798_000_000_500_000_000;

    public function testSendsThePprofWithTheIngestParameters(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);

        $delivery = $sink->send($this->profile(10_000_000, self::START_NS + 200_000_000));

        self::assertTrue($delivery->isDelivered());
        self::assertCount(1, $transport->sent);
        $payload = $transport->payload();
        self::assertSame('application/x-protobuf', $payload->contentType);
        self::assertSame([
            'name' => 'blog',
            'format' => 'pprof',
            'from' => '1798000000',
            'until' => '1798000001',
            'spyName' => 'tracewick',
            'sampleRate' => '100',
        ], $payload->parameters);
        self::assertSame(self::START_NS, ProtobufMessage::decode($payload->body)->int(9));
    }

    public function testKeepsAWholeSecondEnd(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);

        $sink->send($this->profile(10_000_000, 1_798_000_002_000_000_000));

        self::assertSame('1798000002', $transport->payload()->parameters['until'] ?? null);
    }

    public function testRoundsAnEndJustPastASecondUp(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);

        $sink->send($this->profile(10_000_000, 1_798_000_002_000_000_001));

        self::assertSame('1798000003', $transport->payload()->parameters['until'] ?? null);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function sampleRates(): iterable
    {
        yield '10 ms' => [10_000_000, '100'];
        yield '7 ms rounds to nearest' => [7_000_000, '143'];
        yield '3 ms rounds to nearest' => [3_000_000, '333'];
        yield '1 µs' => [1_000, '1000000'];
        yield '3 ns rounds down' => [3, '333333333'];
        yield '400 ms rounds half up' => [400_000_000, '3'];
        yield '625 ms rounds up from .6' => [625_000_000, '2'];
        yield 'longer than a second' => [3_000_000_000, '1'];
    }

    #[DataProvider('sampleRates')]
    public function testDerivesTheSampleRateFromThePeriod(int $periodNs, string $expected): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);

        $sink->send($this->profile($periodNs, self::START_NS + 200_000_000));

        self::assertSame($expected, $transport->payload()->parameters['sampleRate'] ?? null);
    }

    public function testReplacesDotsInLabelKeys(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);
        $profile = $this->profile(10_000_000, self::START_NS + 200_000_000)
            ->withLabels(new Labels(['http.route' => '/posts/{id}', 'deployment.environment.name' => 'prod', 'region' => 'a.b']));

        $sink->send($profile);

        $pprof = ProtobufMessage::decode($transport->payload()->body);
        $strings = $pprof->strings(6);
        $labels = [];
        $samples = $pprof->messages(2);
        self::assertCount(1, $samples);
        foreach ($samples[0]->messages(3) as $label) {
            $labels[$strings[$label->int(1)] ?? ''] = $strings[$label->int(2)] ?? '';
        }

        self::assertSame(['deployment_environment_name' => 'prod', 'http_route' => '/posts/{id}', 'region' => 'a.b'], $labels);
    }

    public function testSkipsAnEmptyProfile(): void
    {
        $transport = new ScriptedTransport();
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);

        $delivery = $sink->send(new Profile(EventType::Wall, 10_000_000, self::START_NS, self::START_NS + 1_000));

        self::assertSame(DeliveryStatus::Skipped, $delivery->status);
        self::assertSame('The profile has no samples.', $delivery->reason);
        self::assertSame([], $transport->sent);
    }

    public function testReturnsTheTransportResult(): void
    {
        $transport = new ScriptedTransport(Delivery::rejected('HTTP 400 from pyroscope:4040'));
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);

        $delivery = $sink->send($this->profile(10_000_000, self::START_NS + 200_000_000));

        self::assertSame(DeliveryStatus::Rejected, $delivery->status);
        self::assertSame('HTTP 400 from pyroscope:4040', $delivery->reason);
    }

    public function testNeverThrows(): void
    {
        $transport = new ScriptedTransport(new \RuntimeException('boom'));
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);

        $delivery = $sink->send($this->profile(10_000_000, self::START_NS + 200_000_000));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertSame('Cannot send the profile to Pyroscope: boom', $delivery->reason);
    }

    public function testSendsOverHttpBehindTheCircuitBreakerByDefault(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        $sink = new PyroscopeSink(new PyroscopeConfig('http://' . $address, 'blog', circuitBreaker: new CircuitBreakerConfig(1, 60_000)));
        $profile = $this->profile(10_000_000, self::START_NS + 200_000_000);

        $first = $sink->send($profile);
        $second = $sink->send($profile);

        self::assertSame(DeliveryStatus::Failed, $first->status);
        self::assertStringStartsWith('Cannot connect to ' . $address, $first->reason);
        self::assertSame(DeliveryStatus::Skipped, $second->status);
    }

    public function testForwardsASpoolFileUnderItsOwnApplicationName(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);
        $profile = $this->profile(10_000_000, self::START_NS + 200_000_000)->withLabels(new Labels(['http.route' => '/posts/{id}']));

        $delivery = $sink->forward($this->spoolFile($profile));

        self::assertTrue($delivery->isDelivered());
        $payload = $transport->payload();
        self::assertSame('application/x-protobuf', $payload->contentType);
        self::assertSame([
            'name' => 'shop',
            'format' => 'pprof',
            'from' => '1798000000',
            'until' => '1798000001',
            'spyName' => 'tracewick',
            'sampleRate' => '100',
        ], $payload->parameters);
        $pprof = ProtobufMessage::decode($payload->body);
        $strings = $pprof->strings(6);
        $label = $pprof->messages(2)[0]->messages(3)[0];
        self::assertSame(['http_route', '/posts/{id}'], [$strings[$label->int(1)] ?? '', $strings[$label->int(2)] ?? '']);
    }

    public function testForwardsAPayloadWithoutDottedKeysUnchanged(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);
        $file = $this->spoolFile($this->profile(10_000_000, self::START_NS + 200_000_000)->withLabels(new Labels(['env' => 'prod'])));

        $sink->forward($file);

        self::assertSame($file->payload, $transport->payload()->body);
    }

    /**
     * @return iterable<string, array{SpoolFile, string}>
     */
    public static function unsendableFiles(): iterable
    {
        $pprof = "\x0a\x00";
        yield 'unknown format' => [new SpoolFile('shop', EventType::Wall, 10, 1, 2, [], 'x-unknown', $pprof), 'Payload format "x-unknown" cannot be sent to Pyroscope.'];
        yield 'period zero' => [new SpoolFile('shop', EventType::Wall, 0, 1, 2, [], 'pprof', $pprof), 'The spool file has an invalid sampling period or time window.'];
        yield 'end before start' => [new SpoolFile('shop', EventType::Wall, 10, 2, 1, [], 'pprof', $pprof), 'The spool file has an invalid sampling period or time window.'];
        yield 'invalid application name' => [new SpoolFile('my shop', EventType::Wall, 10, 1, 2, [], 'pprof', $pprof), 'The application name must be'];
        yield 'invalid pprof' => [new SpoolFile('shop', EventType::Wall, 10, 1, 2, [], 'pprof', "\x12\x05abc"), 'A field of 5 bytes runs past the end of the message.'];
    }

    #[DataProvider('unsendableFiles')]
    public function testRejectsAFilePyroscopeCannotAccept(SpoolFile $file, string $reason): void
    {
        $transport = new ScriptedTransport();
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);

        $delivery = $sink->forward($file);

        self::assertSame(DeliveryStatus::Rejected, $delivery->status);
        self::assertStringStartsWith($reason, $delivery->reason);
        self::assertSame([], $transport->sent);
    }

    public function testNeverThrowsWhenForwarding(): void
    {
        $transport = new ScriptedTransport(new \RuntimeException('boom'));
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);

        $delivery = $sink->forward($this->spoolFile($this->profile(10_000_000, self::START_NS + 200_000_000)));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertSame('Cannot send the profile to Pyroscope: boom', $delivery->reason);
    }

    private function spoolFile(Profile $profile): SpoolFile
    {
        return new SpoolFile('shop', $profile->eventType, $profile->periodNs, $profile->startNs, $profile->endNs, $profile->labels->toArray(), 'pprof', (new PprofEncoder())->encode($profile));
    }

    private function profile(int $periodNs, int $endNs): Profile
    {
        return new Profile(EventType::Wall, $periodNs, self::START_NS, $endNs, [new Frame('work')], [new Stack([0], 3)]);
    }
}
