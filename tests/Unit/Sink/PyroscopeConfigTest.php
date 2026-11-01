<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\HttpConfig;

#[CoversClass(PyroscopeConfig::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(CircuitBreakerConfig::class)]
#[UsesClass(HttpConfig::class)]
final class PyroscopeConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new PyroscopeConfig('http://pyroscope:4040', 'blog');
        $http = $config->httpConfig();

        self::assertSame('http://pyroscope:4040/ingest', $http->url);
        self::assertSame('/ingest', $http->target);
        self::assertSame(200, $http->connectTimeoutMs);
        self::assertSame(1_000, $http->timeoutMs);
        self::assertSame([], $http->headers);
        self::assertEquals(new CircuitBreakerConfig(), $config->circuitBreaker);
    }

    public function testBuildsTheIngestUrlUnderABasePath(): void
    {
        $config = new PyroscopeConfig('https://profiles.example.com/prefix/', 'blog', connectTimeoutMs: 100, timeoutMs: 300);
        $http = $config->httpConfig();

        self::assertSame('https://profiles.example.com/prefix/ingest', $http->url);
        self::assertSame(100, $http->connectTimeoutMs);
        self::assertSame(300, $http->timeoutMs);
    }

    public function testSendsCredentialsAndTenantAsHeaders(): void
    {
        $config = new PyroscopeConfig('https://profiles.example.com', 'blog', '123456', 'glc_token:with=chars', 'team-a');

        self::assertSame([
            'Authorization' => 'Basic ' . base64_encode('123456:glc_token:with=chars'),
            'X-Scope-OrgID' => 'team-a',
        ], $config->httpConfig()->headers);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validNames(): iterable
    {
        yield 'simple' => ['blog'];
        yield 'all allowed characters' => ['My-App_2.prod'];
        yield 'digit first' => ['1app'];
        yield 'longest' => [str_repeat('a', 256)];
    }

    #[DataProvider('validNames')]
    public function testAcceptsApplicationNames(string $name): void
    {
        self::assertSame($name, (new PyroscopeConfig('http://pyroscope:4040', $name))->applicationName);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['my app'];
        yield 'leading dot' => ['.app'];
        yield 'leading dash' => ['-app'];
        yield 'braces' => ['app{env}'];
        yield 'trailing newline' => ["app\n"];
        yield 'too long' => [str_repeat('a', 257)];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsApplicationNames(string $name): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('The application name must be 1 to 256 letters, digits, dots, underscores or dashes, starting with a letter or digit, got "%s".', $name));

        new PyroscopeConfig('http://pyroscope:4040', $name);
    }

    public function testRejectsAUserWithoutAPassword(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Basic authentication needs both a user and a password.');

        new PyroscopeConfig('http://pyroscope:4040', 'blog', basicAuthUser: 'user');
    }

    public function testRejectsAPasswordWithoutAUser(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Basic authentication needs both a user and a password.');

        new PyroscopeConfig('http://pyroscope:4040', 'blog', basicAuthPassword: 'secret');
    }

    public function testRejectsAUserWithAColon(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The basic authentication user must not contain a colon.');

        new PyroscopeConfig('http://pyroscope:4040', 'blog', 'us:er', 'secret');
    }

    public function testValidatesTheUrl(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The URL must be an absolute http or https URL, got "pyroscope:4040/ingest".');

        new PyroscopeConfig('pyroscope:4040', 'blog');
    }

    public function testValidatesTheTimeouts(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The timeout must be between the connect timeout and 60 s, got 100 ms.');

        new PyroscopeConfig('http://pyroscope:4040', 'blog', connectTimeoutMs: 500, timeoutMs: 100);
    }

    public function testValidatesTheTenant(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The value of header "X-Scope-OrgID" contains a line break or NUL byte.');

        new PyroscopeConfig('http://pyroscope:4040', 'blog', tenantId: "team\r\nX-Evil: 1");
    }
}
