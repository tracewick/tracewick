<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Transport\CachingDnsResolver;
use Tracewick\Transport\DnsResolver;
use Tracewick\Transport\SystemDnsResolver;

#[CoversClass(CachingDnsResolver::class)]
#[UsesClass(SystemDnsResolver::class)]
final class CachingDnsResolverTest extends TestCase
{
    public function testCachesUntilTheTtlExpires(): void
    {
        $inner = new class implements DnsResolver {
            public int $calls = 0;

            public function resolve(string $host): string
            {
                ++$this->calls;

                return '203.0.113.' . $this->calls;
            }
        };
        $clock = new ManualClock(1_000);
        $resolver = new CachingDnsResolver($inner, $clock, 1_000);

        self::assertSame('203.0.113.1', $resolver->resolve('pyroscope.example'));
        self::assertSame('203.0.113.1', $resolver->resolve('pyroscope.example'));
        self::assertSame(1, $inner->calls);

        $clock->advanceMs(1);
        self::assertSame('203.0.113.2', $resolver->resolve('pyroscope.example'));
        self::assertSame(2, $inner->calls);
    }

    public function testLeavesIpLiteralsToTheInnerResolver(): void
    {
        $resolver = new CachingDnsResolver(new SystemDnsResolver(), new ManualClock());

        self::assertSame('127.0.0.1', $resolver->resolve('127.0.0.1'));
    }
}
