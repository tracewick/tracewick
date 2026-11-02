<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Diagnostics\ArrayCounterStore;
use Tracewick\Diagnostics\NullCounterStore;
use Tracewick\Exception\LogicException;
use Tracewick\Sampler\EventType;
use Tracewick\Sink\CardinalityGate;
use Tracewick\Sink\SpoolAggregationKey;

#[CoversClass(CardinalityGate::class)]
#[UsesClass(ArrayCounterStore::class)]
#[UsesClass(NullCounterStore::class)]
#[UsesClass(SpoolAggregationKey::class)]
#[UsesClass(LogicException::class)]
final class CardinalityGateTest extends TestCase
{
    public function testAllowsKeysUpToTheLimitThenKeepsBaseLabels(): void
    {
        $store = new ArrayCounterStore();
        $gate = new CardinalityGate($store, 2);

        $first = $gate->limitLabels('blog', ['env' => 'prod', 'route' => 'a'], EventType::Wall, 10);
        $second = $gate->limitLabels('blog', ['env' => 'prod', 'route' => 'b'], EventType::Wall, 10);
        $third = $gate->limitLabels('blog', ['env' => 'prod', 'route' => 'c', 'service' => 'api'], EventType::Wall, 10);

        self::assertSame(['env' => 'prod', 'route' => 'a'], $first);
        self::assertSame(['env' => 'prod', 'route' => 'b'], $second);
        self::assertSame(['service' => 'api', 'env' => 'prod', 'cardinality' => 'other'], $third);

        // Known keys stay themselves.
        self::assertSame(
            ['env' => 'prod', 'route' => 'a'],
            $gate->limitLabels('blog', ['env' => 'prod', 'route' => 'a'], EventType::Wall, 10),
        );

        foreach (array_keys($store->all()) as $key) {
            self::assertStringStartsWith('tracewick:agg:', $key);
        }
    }

    public function testNullStoreNeverCollapses(): void
    {
        $gate = new CardinalityGate(new NullCounterStore(), 1);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(
                ['route' => (string) $i],
                $gate->limitLabels('blog', ['route' => (string) $i], EventType::Wall, 10),
            );
        }
    }

    public function testRejectsInvalidMax(): void
    {
        $this->expectException(LogicException::class);
        new CardinalityGate(new ArrayCounterStore(), 0);
    }
}
