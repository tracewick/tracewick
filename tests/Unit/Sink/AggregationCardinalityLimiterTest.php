<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Diagnostics\ArrayCounterStore;
use Tracewick\Exception\LogicException;
use Tracewick\Sampler\EventType;
use Tracewick\Sink\AggregationCardinalityLimiter;
use Tracewick\Sink\SpoolAggregationKey;

#[CoversClass(AggregationCardinalityLimiter::class)]
#[UsesClass(ArrayCounterStore::class)]
#[UsesClass(SpoolAggregationKey::class)]
#[UsesClass(LogicException::class)]
final class AggregationCardinalityLimiterTest extends TestCase
{
    public function testAllowsKeysUpToTheLimitThenCollapses(): void
    {
        $store = new ArrayCounterStore();
        $limiter = new AggregationCardinalityLimiter($store, 2);

        $first = $limiter->limitLabels('blog', ['route' => 'a'], EventType::Wall, 10);
        $second = $limiter->limitLabels('blog', ['route' => 'b'], EventType::Wall, 10);
        $third = $limiter->limitLabels('blog', ['route' => 'c'], EventType::Wall, 10);

        self::assertSame(['route' => 'a'], $first);
        self::assertSame(['route' => 'b'], $second);
        self::assertSame(['cardinality' => 'other'], $third);

        // Known keys stay themselves.
        self::assertSame(['route' => 'a'], $limiter->limitLabels('blog', ['route' => 'a'], EventType::Wall, 10));
    }

    public function testRejectsInvalidMax(): void
    {
        $this->expectException(LogicException::class);
        new AggregationCardinalityLimiter(new ArrayCounterStore(), 0);
    }
}
