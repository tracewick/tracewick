<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Encoder\CompactStacksCodec;
use Tracewick\Encoder\Pprof\ProtobufException;
use Tracewick\Encoder\Pprof\ProtobufReader;
use Tracewick\Encoder\Pprof\ProtobufWriter;
use Tracewick\Encoder\Pprof\StringTable;
use Tracewick\Encoder\PprofEncoder;
use Tracewick\Encoder\ProfileParts;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Sink\PyroscopePushRequest;
use Tracewick\Sink\PyroscopeSink;
use Tracewick\Sink\SpoolFile;
use Tracewick\Tests\Support\ProtobufMessage;
use Tracewick\Tests\Support\RecordingLogger;
use Tracewick\Tests\Support\ScriptedTransport;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\CachingDnsResolver;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\CircuitBreakerTransport;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;
use Tracewick\Transport\FallbackCircuitBreakerStore;
use Tracewick\Transport\FileCircuitBreakerStore;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\HttpTransport;
use Tracewick\Transport\InMemoryCircuitBreakerStore;
use Tracewick\Transport\Payload;
use Tracewick\Transport\SystemDnsResolver;

#[CoversClass(PyroscopeSink::class)]
#[UsesClass(PyroscopeConfig::class)]
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
#[UsesClass(Delivery::class)]
#[UsesClass(Payload::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(PprofEncoder::class)]
#[UsesClass(CompactStacksCodec::class)]
#[UsesClass(ProfileParts::class)]
#[UsesClass(ProtobufWriter::class)]
#[UsesClass(ProtobufReader::class)]
#[UsesClass(ProtobufException::class)]
#[UsesClass(SpoolFile::class)]
#[UsesClass(StringTable::class)]
#[UsesClass(Profile::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
final class PyroscopeSinkTest extends TestCase
{
    private const START_NS = 1_798_000_000_500_000_000;

    protected function setUp(): void
    {
        DiagnosticLogger::resetRateLimits();
    }

    public function testSendsAPushRequestWithSeriesLabels(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);
        $profile = $this->profile(10_000_000, self::START_NS + 200_000_000)
            ->withLabels(new Labels(['http.route' => '/posts/{id}', 'region' => 'eu']));

        $delivery = $sink->send($profile);

        self::assertTrue($delivery->isDelivered());
        self::assertCount(1, $transport->sent);
        $payload = $transport->payload();
        self::assertSame(PyroscopePushRequest::MEDIA_TYPE, $payload->contentType);
        self::assertSame([], $payload->parameters);

        [$labels, $rawProfile, $id] = $this->decodePush($payload->body);
        self::assertSame([
            '__name__' => 'wall',
            'service_name' => 'blog',
            'pyroscope_spy' => 'tracewick',
            'http_route' => '/posts/{id}',
            'region' => 'eu',
        ], $labels);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
        self::assertSame((new PprofEncoder())->encode($profile), $rawProfile);
        $pprof = ProtobufMessage::decode($rawProfile);
        self::assertSame(self::START_NS, $pprof->int(9));
        $samples = $pprof->messages(2);
        self::assertNotEmpty($samples);
        self::assertFalse($samples[0]->has(3));
    }

    public function testKeepsSpanIdInsideThePprofOnly(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);
        $profile = $this->profile(10_000_000, self::START_NS + 200_000_000)
            ->withLabels(new Labels(['span_id' => '00f067aa0ba902b7', 'env' => 'prod']));

        $sink->send($profile);

        [$labels, $rawProfile] = $this->decodePush($transport->payload()->body);
        self::assertSame('prod', $labels['env'] ?? null);
        self::assertArrayNotHasKey('span_id', $labels);

        $pprof = ProtobufMessage::decode($rawProfile);
        $strings = $pprof->strings(6);
        $sampleLabels = ($pprof->messages(2)[0] ?? self::fail('No sample.'))->messages(3);
        self::assertCount(1, $sampleLabels);
        self::assertSame(
            'span_id=00f067aa0ba902b7',
            ($strings[$sampleLabels[0]->int(1)] ?? '') . '=' . ($strings[$sampleLabels[0]->int(2)] ?? ''),
        );
    }

    public function testMapsCpuEventTypeToProcessCpuSeries(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);
        $profile = new Profile(EventType::Cpu, 10_000_000, self::START_NS, self::START_NS + 200_000_000, [new Frame('work')], [new Stack([0], 3)]);

        $sink->send($profile);

        [$labels] = $this->decodePush($transport->payload()->body);
        self::assertSame('process_cpu', $labels['__name__'] ?? null);
    }

    public function testSkipsAnEmptyProfile(): void
    {
        $transport = new ScriptedTransport();
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'blog'), $transport);

        $delivery = $sink->send(new Profile(EventType::Wall, 10_000_000, self::START_NS, self::START_NS + 1_000));

        self::assertSame(DeliveryStatus::Empty, $delivery->status);
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

    public function testSendsOverHttpBehindACircuitBreakerSharedByTheProcessesOfTheHost(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);
        $directory = new TemporaryDirectory();

        try {
            $circuitBreaker = new CircuitBreakerConfig(1, 60_000, $directory->path);
            $profile = $this->profile(10_000_000, self::START_NS + 200_000_000);

            $logger = new RecordingLogger();
            $first = (new PyroscopeSink(new PyroscopeConfig('http://' . $address, 'blog', tenantId: 'team-a', circuitBreaker: $circuitBreaker), logger: $logger))->send($profile);
            $second = (new PyroscopeSink(new PyroscopeConfig('http://' . $address . '/', 'shop', tenantId: 'team-a', circuitBreaker: $circuitBreaker)))->send($profile);

            self::assertSame(DeliveryStatus::Failed, $first->status);
            self::assertStringStartsWith('Cannot connect to ' . $address, $first->reason);
            self::assertSame(DeliveryStatus::Skipped, $second->status);
            self::assertSame(
                ['tracewick-circuit-' . md5('http://' . $address . PyroscopePushRequest::PATH . "\nteam-a")],
                $directory->entries(),
            );
            self::assertSame(['warning: Tracewick stopped sending profiles for {pauseMs} ms after {failures} failed deliveries in a row: {reason}'], $logger->lines());
        } finally {
            $directory->remove();
        }
    }

    public function testForwardsASpoolFileUnderItsOwnApplicationName(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);
        $profile = $this->profile(10_000_000, self::START_NS + 200_000_000)->withLabels(new Labels(['http.route' => '/posts/{id}']));

        $delivery = $sink->forward($this->spoolFile($profile));

        self::assertTrue($delivery->isDelivered());
        $payload = $transport->payload();
        self::assertSame(PyroscopePushRequest::MEDIA_TYPE, $payload->contentType);
        [$labels, $rawProfile] = $this->decodePush($payload->body);
        self::assertSame('shop', $labels['service_name'] ?? null);
        self::assertSame('/posts/{id}', $labels['http_route'] ?? null);
        self::assertSame(
            (new PprofEncoder())->encode($profile),
            $rawProfile,
        );
        $samples = ProtobufMessage::decode($rawProfile)->messages(2);
        self::assertNotEmpty($samples);
        self::assertFalse($samples[0]->has(3));
    }

    public function testForwardsACompactStacksPayloadAsPprofInsidePush(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);
        $profile = $this->profile(10_000_000, self::START_NS + 200_000_000)->withLabels(new Labels(['http.route' => '/posts/{id}']));

        $delivery = $sink->forward($this->spoolFile($profile));

        self::assertTrue($delivery->isDelivered());
        [, $rawProfile] = $this->decodePush($transport->payload()->body);
        self::assertSame((new PprofEncoder())->encode($profile), $rawProfile);
    }

    /**
     * @return iterable<string, array{SpoolFile, non-empty-string}>
     */
    public static function unsendableFiles(): iterable
    {
        $compact = (new CompactStacksCodec())->encode(new Profile(EventType::Wall, 10, 1, 2, [new Frame('work')], [new Stack([0], 1)]));
        yield 'period zero' => [new SpoolFile('shop', EventType::Wall, 0, 1, 2, [], SpoolFile::FORMAT_COMPACT_STACKS, $compact), 'The spool file has an invalid sampling period or time window.'];
        yield 'end before start' => [new SpoolFile('shop', EventType::Wall, 10, 2, 1, [], SpoolFile::FORMAT_COMPACT_STACKS, $compact), 'The spool file has an invalid sampling period or time window.'];
        yield 'invalid application name' => [new SpoolFile('my shop', EventType::Wall, 10, 1, 2, [], SpoolFile::FORMAT_COMPACT_STACKS, $compact), 'The application name must be'];
        yield 'invalid compact payload' => [new SpoolFile('shop', EventType::Wall, 10, 1, 2, [], SpoolFile::FORMAT_COMPACT_STACKS, "\x02"), 'Compact stacks schema version 2 is not supported.'];
    }

    /**
     * @param non-empty-string $reason
     */
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

    public function testAcceptsTheShortestPeriodAndAnInstantWindow(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);
        $profile = new Profile(EventType::Cpu, 1, self::START_NS, self::START_NS, [new Frame('work')], [new Stack([0], 1)]);

        $delivery = $sink->forward($this->spoolFile($profile));

        self::assertTrue($delivery->isDelivered());
        [$labels] = $this->decodePush($transport->payload()->body);
        self::assertSame('process_cpu', $labels['__name__'] ?? null);
    }

    public function testNeverThrowsWhenForwarding(): void
    {
        $transport = new ScriptedTransport(new \RuntimeException('boom'));
        $sink = new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain'), $transport);

        $delivery = $sink->forward($this->spoolFile($this->profile(10_000_000, self::START_NS + 200_000_000)));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertSame('Cannot send the profile to Pyroscope: boom', $delivery->reason);
    }

    /**
     * @return array{array<string, string>, string, string}
     */
    private function decodePush(string $body): array
    {
        $series = ProtobufMessage::decode($body)->messages(1)[0] ?? self::fail('No series.');
        $labels = [];
        foreach ($series->messages(1) as $label) {
            $labels[$label->string(1)] = $label->string(2);
        }

        $sample = $series->messages(2)[0] ?? self::fail('No sample.');

        return [$labels, $sample->string(1), $sample->string(2)];
    }

    private function spoolFile(Profile $profile): SpoolFile
    {
        return new SpoolFile(
            'shop',
            $profile->eventType,
            $profile->periodNs,
            $profile->startNs,
            $profile->endNs,
            $profile->labels->toArray(),
            SpoolFile::FORMAT_COMPACT_STACKS,
            (new CompactStacksCodec())->encode($profile),
            SpoolFile::VERSION_2,
        );
    }

    private function profile(int $periodNs, int $endNs): Profile
    {
        return new Profile(EventType::Wall, $periodNs, self::START_NS, $endNs, [new Frame('work')], [new Stack([0], 3)]);
    }
}
