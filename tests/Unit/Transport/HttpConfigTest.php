<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Transport\HttpConfig;

#[CoversClass(HttpConfig::class)]
final class HttpConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new HttpConfig('http://pyroscope:4040/ingest');

        self::assertFalse($config->tls);
        self::assertSame('pyroscope', $config->host);
        self::assertSame(4040, $config->port);
        self::assertSame('/ingest', $config->target);
        self::assertSame('pyroscope:4040', $config->authority());
        self::assertSame(200, $config->connectTimeoutMs);
        self::assertSame(1_000, $config->timeoutMs);
        self::assertSame([], $config->headers);
    }

    /**
     * @return iterable<string, array{string, bool, string, int, string, string}>
     */
    public static function urls(): iterable
    {
        yield 'http default port' => ['http://example.com', false, 'example.com', 80, '/', 'example.com'];
        yield 'https default port' => ['HTTPS://example.com/a/b', true, 'example.com', 443, '/a/b', 'example.com'];
        yield 'https custom port' => ['https://example.com:8443/x?y=1', true, 'example.com', 8443, '/x?y=1', 'example.com:8443'];
        yield 'http on port 443' => ['http://example.com:443/', false, 'example.com', 443, '/', 'example.com:443'];
        yield 'ipv6' => ['http://[::1]:4040/ingest', false, '[::1]', 4040, '/ingest', '[::1]:4040'];
    }

    #[DataProvider('urls')]
    public function testParsesTheUrl(string $url, bool $tls, string $host, int $port, string $target, string $authority): void
    {
        $config = new HttpConfig($url);

        self::assertSame($tls, $config->tls);
        self::assertSame($host, $config->host);
        self::assertSame($port, $config->port);
        self::assertSame($target, $config->target);
        self::assertSame($authority, $config->authority());
    }

    public function testAcceptsTimeoutBoundsAndHeaders(): void
    {
        $config = new HttpConfig('http://example.com', 1, 60_000, ['Authorization' => 'Basic abc', 'X-Scope-OrgID' => 'tenant']);

        self::assertSame(1, $config->connectTimeoutMs);
        self::assertSame(60_000, $config->timeoutMs);
        self::assertSame(['Authorization' => 'Basic abc', 'X-Scope-OrgID' => 'tenant'], $config->headers);
        self::assertSame(500, (new HttpConfig('http://example.com', 500, 500))->timeoutMs);
        self::assertSame(60_000, (new HttpConfig('http://example.com', 60_000, 60_000))->connectTimeoutMs);
    }

    /**
     * @return iterable<string, array{string, int, int, array<string, string>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'relative' => ['/ingest', 500, 2_000, [], 'absolute http or https URL'];
        yield 'no host' => ['http:///ingest', 500, 2_000, [], 'absolute http or https URL'];
        yield 'other scheme' => ['ftp://example.com', 500, 2_000, [], 'absolute http or https URL'];
        yield 'credentials' => ['http://user:pass@example.com', 500, 2_000, [], 'credentials or a fragment'];
        yield 'user only' => ['http://user@example.com', 500, 2_000, [], 'credentials or a fragment'];
        yield 'fragment' => ['http://example.com/#x', 500, 2_000, [], 'credentials or a fragment'];
        yield 'connect timeout zero' => ['http://example.com', 0, 2_000, [], 'connect timeout'];
        yield 'connect timeout too long' => ['http://example.com', 60_001, 60_001, [], 'connect timeout'];
        yield 'timeout below connect' => ['http://example.com', 500, 499, [], 'The timeout'];
        yield 'timeout too long' => ['http://example.com', 500, 60_001, [], 'The timeout'];
        yield 'header name' => ['http://example.com', 500, 2_000, ['Bad Header' => 'x'], 'Invalid header name'];
        yield 'header name with a trailing newline' => ['http://example.com', 500, 2_000, ["X-A\n" => 'x'], 'Invalid header name'];
        yield 'reserved header' => ['http://example.com', 500, 2_000, ['content-length' => '1'], 'set by the transport'];
        yield 'host header' => ['http://example.com', 500, 2_000, ['Host' => 'x'], 'set by the transport'];
        yield 'header injection' => ['http://example.com', 500, 2_000, ['X-A' => "a\r\nX-B: b"], 'line break'];
        yield 'nul byte' => ['http://example.com', 500, 2_000, ['X-A' => "a\0"], 'line break'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('invalid')]
    public function testRejectsInvalidValues(string $url, int $connectTimeoutMs, int $timeoutMs, array $headers, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        new HttpConfig($url, $connectTimeoutMs, $timeoutMs, $headers);
    }
}
