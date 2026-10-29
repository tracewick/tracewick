<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
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
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\CircuitBreakerTransport;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\HttpTransport;
use Tracewick\Transport\Payload;

/**
 * Sends profiles to the Pyroscope server of the development stand and reads them back.
 */
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
#[UsesClass(StringTable::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
final class PyroscopeSinkTest extends TestCase
{
    private string $serverUrl;

    protected function setUp(): void
    {
        $url = getenv('PYROSCOPE_URL');
        if (false === $url || '' === $url) {
            self::markTestSkipped('PYROSCOPE_URL is not set; run the test inside the stand.');
        }

        $this->serverUrl = $url;
    }

    public function testPyroscopeStoresTheProfileWithItsLabels(): void
    {
        $application = 'tracewick-it-' . bin2hex(random_bytes(4));
        $sink = new PyroscopeSink(new PyroscopeConfig($this->serverUrl, $application));
        $nowNs = (new SystemClock())->nowNs();
        $profile = new Profile(
            EventType::Wall,
            10_000_000,
            $nowNs - 1_000_000_000,
            $nowNs,
            [new Frame('main'), new Frame('work', 'App\Worker', '/app/src/Worker.php', 12)],
            [new Stack([0, 1], 7)],
            new Labels(['http.route' => '/posts/{id}']),
        );

        $delivery = $sink->send($profile);

        self::assertTrue($delivery->isDelivered(), $delivery->reason);
        self::assertSame([$application], $this->labelValues('service_name', $nowNs, $application));
        self::assertSame(['/posts/{id}'], $this->labelValues('http_route', $nowNs, $application));
        self::assertSame(['tracewick'], $this->labelValues('pyroscope_spy', $nowNs, $application));
    }

    /**
     * Values of a label in the application's series, retried until there are some.
     *
     * Pyroscope ingests asynchronously, and a freshly started server only serves the first
     * ingested data about a minute later, once its read path notices that data exists.
     *
     * @return list<string>
     */
    private function labelValues(string $name, int $aroundNs, string $application): array
    {
        $aroundMs = intdiv($aroundNs, 1_000_000);
        $request = [
            'name' => $name,
            'matchers' => [\sprintf('{service_name="%s"}', $application)],
            'start' => $aroundMs - 60_000,
            'end' => $aroundMs + 60_000,
        ];

        $deadline = hrtime(true) + 90_000_000_000;
        do {
            $values = $this->query('/querier.v1.QuerierService/LabelValues', $request)['names'] ?? [];
            if ([] !== $values) {
                break;
            }

            usleep(200_000);
        } while (hrtime(true) < $deadline);

        self::assertIsArray($values);
        self::assertContainsOnlyString($values);

        return array_values($values);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<mixed>
     */
    private function query(string $path, array $request): array
    {
        $response = file_get_contents(rtrim($this->serverUrl, '/') . $path, false, stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($request, \JSON_THROW_ON_ERROR),
            'timeout' => 5,
        ]]));
        self::assertIsString($response);
        $decoded = json_decode($response, true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
