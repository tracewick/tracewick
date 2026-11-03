<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Transport\SystemDnsResolver;

#[CoversClass(SystemDnsResolver::class)]
final class SystemDnsResolverTest extends TestCase
{
    public function testReturnsIpv4LiteralsUnchanged(): void
    {
        self::assertSame('127.0.0.1', (new SystemDnsResolver())->resolve('127.0.0.1'));
    }

    public function testReturnsBracketedIpv6LiteralsUnchanged(): void
    {
        self::assertSame('[::1]', (new SystemDnsResolver())->resolve('[::1]'));
    }

    public function testReturnsTheHostWhenLookupFindsNothing(): void
    {
        $host = 'no-such-host.invalid';

        self::assertSame($host, (new SystemDnsResolver())->resolve($host));
    }

    public function testResolvesLocalhostToAnAddress(): void
    {
        $address = (new SystemDnsResolver())->resolve('localhost');

        self::assertNotFalse(@inet_pton($address), \sprintf('Expected an IP for localhost, got "%s".', $address));
    }
}
