<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Diagnostics\HostCounterKeys;

#[CoversClass(HostCounterKeys::class)]
final class HostCounterKeysTest extends TestCase
{
    public function testExposesDistinctStableCounterNames(): void
    {
        /** @var list<string> $keys */
        $keys = [
            HostCounterKeys::PROFILES_COLLECTED,
            HostCounterKeys::PROFILES_DROPPED,
            HostCounterKeys::PROFILES_DELIVERED,
            HostCounterKeys::PROFILES_REJECTED,
            HostCounterKeys::PROFILES_FAILED,
            HostCounterKeys::PROFILES_SKIPPED,
            HostCounterKeys::PROFILES_EMPTY,
            HostCounterKeys::PROFILES_SWALLOWED,
        ];

        self::assertCount(8, array_unique($keys));
        foreach ($keys as $key) {
            self::assertStringStartsWith('tracewick:', $key);
        }
    }
}
