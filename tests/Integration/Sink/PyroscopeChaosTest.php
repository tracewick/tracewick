<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Encoder\Pprof\ProtobufWriter;
use Tracewick\Encoder\Pprof\StringTable;
use Tracewick\Encoder\PprofEncoder;
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
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Tests\Support\Toxiproxy;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\CircuitBreakerTransport;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;
use Tracewick\Transport\FileCircuitBreakerStore;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\HttpTransport;
use Tracewick\Transport\Payload;
use Tracewick\Transport\TransportException;

/**
 * Sends profiles to Pyroscope through Toxiproxy while it injects network failures, and checks
 * that delivery fails within its time budget and that the circuit breaker stops trying.
 */
#[CoversClass(PyroscopeSink::class)]
#[UsesClass(PyroscopeConfig::class)]
#[UsesClass(PyroscopePushRequest::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(HttpConfig::class)]
#[UsesClass(HttpTransport::class)]
#[UsesClass(TransportException::class)]
#[UsesClass(CircuitBreakerConfig::class)]
#[UsesClass(CircuitBreakerTransport::class)]
#[UsesClass(CircuitBreakerState::class)]
#[UsesClass(FileCircuitBreakerStore::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Payload::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(PprofEncoder::class)]
#[UsesClass(ProtobufWriter::class)]
#[UsesClass(StringTable::class)]
#[UsesClass(Profile::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
final class PyroscopeChaosTest extends TestCase
{
    private const PROXY = 'pyroscope';

    private const TIMEOUT_MS = 500;

    private string $serverUrl;

    private Toxiproxy $toxiproxy;

    private TemporaryDirectory $stateDirectory;

    protected function setUp(): void
    {
        $serverUrl = getenv('PYROSCOPE_TOXIC_URL');
        $apiUrl = getenv('TOXIPROXY_URL');
        if (false === $serverUrl || '' === $serverUrl || false === $apiUrl || '' === $apiUrl) {
            self::markTestSkipped('PYROSCOPE_TOXIC_URL and TOXIPROXY_URL are not set; run the test inside the stand.');
        }

        $this->serverUrl = $serverUrl;
        $this->toxiproxy = new Toxiproxy($apiUrl);
        $this->toxiproxy->reset();
        // A fresh circuit for every test, so that failures of one test do not open it for the next.
        $this->stateDirectory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        if (isset($this->stateDirectory)) {
            $this->stateDirectory->remove();
        }

        if (isset($this->toxiproxy)) {
            $this->toxiproxy->reset();
        }
    }

    public function testDeliversThroughAHealthyProxy(): void
    {
        $delivery = $this->sink()->send($this->profile());

        self::assertTrue($delivery->isDelivered(), $delivery->reason);
    }

    public function testGivesUpOnASlowServerWithinTheTimeout(): void
    {
        $this->toxiproxy->addToxic(self::PROXY, 'latency', 'downstream', ['latency' => 3_000]);

        [$delivery, $elapsedMs] = $this->timed(fn(): Delivery => $this->sink()->send($this->profile()));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertStringContainsString('Timed out after 500 ms', $delivery->reason);
        self::assertGreaterThanOrEqual(self::TIMEOUT_MS - 10, $elapsedMs);
        self::assertLessThan(self::TIMEOUT_MS + 500, $elapsedMs);
    }

    public function testGivesUpOnAServerThatNeverAnswers(): void
    {
        // A timeout toxic of 0 holds the data forever without closing the connection.
        $this->toxiproxy->addToxic(self::PROXY, 'timeout', 'upstream', ['timeout' => 0]);

        [$delivery, $elapsedMs] = $this->timed(fn(): Delivery => $this->sink()->send($this->profile()));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertLessThan(self::TIMEOUT_MS + 500, $elapsedMs);
    }

    public function testFailsWhenTheConnectionIsReset(): void
    {
        $this->toxiproxy->addToxic(self::PROXY, 'reset_peer', 'upstream', ['timeout' => 0]);

        [$delivery, $elapsedMs] = $this->timed(fn(): Delivery => $this->sink()->send($this->profile()));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertLessThan(self::TIMEOUT_MS + 500, $elapsedMs);
    }

    public function testFailsWhenTheResponseIsCutOff(): void
    {
        $this->toxiproxy->addToxic(self::PROXY, 'limit_data', 'downstream', ['bytes' => 5]);

        $delivery = $this->sink()->send($this->profile());

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertStringContainsString('closed before a response', $delivery->reason);
    }

    public function testCircuitBreakerStopsTryingAndRecovers(): void
    {
        $sink = $this->sink(new CircuitBreakerConfig(2, 300));
        $profile = $this->profile();
        $this->toxiproxy->setEnabled(self::PROXY, false);

        $first = $sink->send($profile);
        $second = $sink->send($profile);
        self::assertSame(DeliveryStatus::Failed, $first->status);
        self::assertSame(DeliveryStatus::Failed, $second->status);
        [$skipped, $elapsedMs] = $this->timed(static fn(): Delivery => $sink->send($profile));
        self::assertSame(DeliveryStatus::Skipped, $skipped->status);
        self::assertLessThan(5, $elapsedMs);

        $this->toxiproxy->setEnabled(self::PROXY, true);
        usleep(350_000);

        $delivery = $sink->send($this->profile());
        self::assertTrue($delivery->isDelivered(), $delivery->reason);
    }

    private function sink(CircuitBreakerConfig $circuitBreaker = new CircuitBreakerConfig()): PyroscopeSink
    {
        return new PyroscopeSink(new PyroscopeConfig(
            $this->serverUrl,
            'tracewick-chaos',
            connectTimeoutMs: 200,
            timeoutMs: self::TIMEOUT_MS,
            circuitBreaker: new CircuitBreakerConfig($circuitBreaker->failureThreshold, $circuitBreaker->openDurationMs, $this->stateDirectory->path),
        ));
    }

    private function profile(): Profile
    {
        $nowNs = (new SystemClock())->nowNs();

        return new Profile(EventType::Wall, 10_000_000, $nowNs - 1_000_000_000, $nowNs, [new Frame('work')], [new Stack([0], 3)]);
    }

    /**
     * @param \Closure(): Delivery $send
     *
     * @return array{Delivery, float}
     */
    private function timed(\Closure $send): array
    {
        $start = hrtime(true);
        $delivery = $send();

        return [$delivery, (hrtime(true) - $start) / 1_000_000];
    }
}
