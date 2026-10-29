<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Tests\Support\FakeHttpServer;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\HttpTransport;
use Tracewick\Transport\Payload;
use Tracewick\Transport\TransportException;

/**
 * Talks to a real HTTP server in a child process, see {@see FakeHttpServer}.
 */
#[CoversClass(HttpTransport::class)]
#[UsesClass(HttpConfig::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Payload::class)]
#[UsesClass(TransportException::class)]
final class HttpTransportTest extends TestCase
{
    public function testSendsAPostRequest(): void
    {
        $server = new FakeHttpServer([['status' => 200]]);
        $transport = new HttpTransport(new HttpConfig($server->url('/ingest?tenant=a'), 500, 2_000, ['Authorization' => 'Basic abc']));

        $delivery = $transport->send(new Payload("bin\0ary", 'application/x-protobuf', ['name' => 'my app', 'from' => '10']));

        self::assertTrue($delivery->isDelivered());
        self::assertSame([
            "POST /ingest?tenant=a&name=my%20app&from=10 HTTP/1.1\r\n"
            . 'Host: ' . substr($server->url(''), 7) . "\r\n"
            . "User-Agent: tracewick\r\n"
            . "Content-Type: application/x-protobuf\r\n"
            . "Content-Length: 7\r\n"
            . "Connection: close\r\n"
            . "Authorization: Basic abc\r\n"
            . "\r\n"
            . "bin\0ary",
        ], $server->requests());
    }

    public function testAddsAQueryStringOnlyWhenThereAreParameters(): void
    {
        $server = new FakeHttpServer([['status' => 204], ['status' => 204]]);
        $transport = new HttpTransport(new HttpConfig($server->url('/ingest')));

        self::assertTrue($transport->send(new Payload('', 'text/plain'))->isDelivered());
        self::assertTrue($transport->send(new Payload('', "text/plain\r\nX-Injected: 1", ['a' => 'b']))->isDelivered());

        $first = $server->request(0);
        $second = $server->request(1);
        self::assertStringStartsWith("POST /ingest HTTP/1.1\r\n", $first);
        self::assertStringContainsString("Content-Length: 0\r\n", $first);
        self::assertStringStartsWith("POST /ingest?a=b HTTP/1.1\r\n", $second);
        self::assertStringContainsString("Content-Type: text/plainX-Injected: 1\r\n", $second);
    }

    public function testSendsLargeBodies(): void
    {
        $server = new FakeHttpServer([['status' => 200]]);
        $body = random_bytes(4_000_000);

        $delivery = (new HttpTransport(new HttpConfig($server->url())))->send(new Payload($body, 'application/octet-stream'));

        self::assertTrue($delivery->isDelivered());
        self::assertStringEndsWith("\r\n\r\n" . $body, $server->request(0));
    }

    /**
     * @return iterable<string, array{int, DeliveryStatus}>
     */
    public static function statuses(): iterable
    {
        yield '200' => [200, DeliveryStatus::Delivered];
        yield '299' => [299, DeliveryStatus::Delivered];
        yield '199' => [199, DeliveryStatus::Rejected];
        yield '300' => [300, DeliveryStatus::Rejected];
        yield '400' => [400, DeliveryStatus::Rejected];
        yield '407' => [407, DeliveryStatus::Rejected];
        yield '408' => [408, DeliveryStatus::Failed];
        yield '409' => [409, DeliveryStatus::Rejected];
        yield '428' => [428, DeliveryStatus::Rejected];
        yield '429' => [429, DeliveryStatus::Failed];
        yield '430' => [430, DeliveryStatus::Rejected];
        yield '499' => [499, DeliveryStatus::Rejected];
        yield '500' => [500, DeliveryStatus::Failed];
        yield '503' => [503, DeliveryStatus::Failed];
    }

    #[DataProvider('statuses')]
    public function testClassifiesStatusCodes(int $status, DeliveryStatus $expected): void
    {
        $server = new FakeHttpServer([['status' => $status]]);

        $delivery = (new HttpTransport(new HttpConfig($server->url())))->send(new Payload('x', 'text/plain'));

        self::assertSame($expected, $delivery->status);
        if (DeliveryStatus::Delivered !== $expected) {
            self::assertSame(\sprintf('HTTP %d from %s', $status, substr($server->url(''), 7)), $delivery->reason);
        }
    }

    public function testAcceptsHttp10AndAStatusLineWithoutReason(): void
    {
        $server = new FakeHttpServer([['raw' => "HTTP/1.0 200\r\n\r\n"], ['raw' => "HTTP/1.1 202\n"]]);
        $transport = new HttpTransport(new HttpConfig($server->url()));

        self::assertTrue($transport->send(new Payload('x', 'text/plain'))->isDelivered());
        self::assertTrue($transport->send(new Payload('x', 'text/plain'))->isDelivered());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidResponses(): iterable
    {
        yield 'not http' => ["SSH-2.0-OpenSSH\r\n"];
        yield 'http 2' => ["HTTP/2 200\r\n"];
        yield 'status out of range' => ["HTTP/1.1 600 X\r\n"];
        yield 'four digit status' => ["HTTP/1.1 2000 X\r\n"];
        yield 'garbage before' => ["xHTTP/1.1 200 OK\r\n"];
        yield 'endless line' => [str_repeat('a', 20_000)];
    }

    #[DataProvider('invalidResponses')]
    public function testFailsOnAnInvalidResponse(string $raw): void
    {
        $server = new FakeHttpServer([['raw' => $raw]]);

        $delivery = (new HttpTransport(new HttpConfig($server->url())))->send(new Payload('x', 'text/plain'));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertStringStartsWith('Invalid HTTP response from 127.0.0.1:', $delivery->reason);
    }

    public function testFailsWhenTheServerClosesWithoutAnswering(): void
    {
        $server = new FakeHttpServer([['raw' => '']]);

        $delivery = (new HttpTransport(new HttpConfig($server->url())))->send(new Payload('x', 'text/plain'));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertStringStartsWith('Connection to 127.0.0.1:', $delivery->reason);
        self::assertStringEndsWith('closed before a response', $delivery->reason);
    }

    public function testFailsWhenTheServerClosesWhileReceiving(): void
    {
        $server = new FakeHttpServer([['close' => true]]);

        $delivery = (new HttpTransport(new HttpConfig($server->url())))->send(new Payload(str_repeat('x', 8_000_000), 'text/plain'));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertMatchesRegularExpression('/^Connection to 127\.0\.0\.1:\d+ closed (while sending|before a response)$/', $delivery->reason);
    }

    public function testGivesUpOnAStalledServerAtTheTimeout(): void
    {
        $server = new FakeHttpServer([['stallMs' => 3_000]]);
        $transport = new HttpTransport(new HttpConfig($server->url(), 100, 300));

        $startNs = hrtime(true);
        $delivery = $transport->send(new Payload('x', 'text/plain'));
        $elapsedMs = (hrtime(true) - $startNs) / 1e6;

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertStringStartsWith('Timed out after 300 ms talking to 127.0.0.1:', $delivery->reason);
        self::assertGreaterThanOrEqual(290, $elapsedMs);
        self::assertLessThan(1_500, $elapsedMs);
    }

    public function testFailsWhenNothingListens(): void
    {
        $server = new FakeHttpServer([]);
        $url = $server->url();
        $server->stop();

        $delivery = (new HttpTransport(new HttpConfig($url)))->send(new Payload('x', 'text/plain'));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertStringStartsWith('Cannot connect to 127.0.0.1:', $delivery->reason);
    }

    public function testFailsWhenTheTlsHandshakeFails(): void
    {
        // The server closes the connection instead of answering the TLS client hello.
        $server = new FakeHttpServer([['close' => true]]);
        $url = str_replace('http://', 'https://', $server->url());

        $delivery = (new HttpTransport(new HttpConfig($url, 500, 1_000)))->send(new Payload('x', 'text/plain'));

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertMatchesRegularExpression('/^TLS handshake with 127\\.0\\.0\\.1:\\d+ failed$/', $delivery->reason);
    }
}
