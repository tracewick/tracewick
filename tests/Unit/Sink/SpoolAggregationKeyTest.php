<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Sampler\EventType;
use Tracewick\Sink\SpoolAggregationKey;

#[CoversClass(SpoolAggregationKey::class)]
final class SpoolAggregationKeyTest extends TestCase
{
    public function testHashesAreStableAndLabelOrderIndependent(): void
    {
        $a = SpoolAggregationKey::hash('blog', ['b' => '2', 'a' => '1'], EventType::Wall, 10_000_000);
        $b = SpoolAggregationKey::hash('blog', ['a' => '1', 'b' => '2'], EventType::Wall, 10_000_000);

        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', $a);
        self::assertSame(substr($a, 0, 2), SpoolAggregationKey::shard($a));
    }

    public function testDifferentKeysProduceDifferentHashes(): void
    {
        $base = SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 10_000_000);

        self::assertNotSame($base, SpoolAggregationKey::hash('shop', ['env' => 'prod'], EventType::Wall, 10_000_000));
        self::assertNotSame($base, SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Cpu, 10_000_000));
        self::assertNotSame($base, SpoolAggregationKey::hash('blog', ['env' => 'prod'], EventType::Wall, 20_000_000));
        self::assertNotSame($base, SpoolAggregationKey::hash('blog', ['env' => 'staging'], EventType::Wall, 10_000_000));
    }
}
