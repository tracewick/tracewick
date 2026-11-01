<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;
use Tracewick\Runtime\ContinuousProfilerConfig;
use Tracewick\Runtime\ProfileAccumulator;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\SpoolAggregationWindow;
use Tracewick\Sink\ApplicationName;

#[CoversClass(ContinuousProfilerConfig::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(SpoolAggregationWindow::class)]
#[UsesClass(Labels::class)]
#[UsesClass(LogicException::class)]
#[UsesClass(ProfilerConfig::class)]
final class ContinuousProfilerConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new ContinuousProfilerConfig('blog', new Labels(['env' => 'prod']));

        self::assertSame('blog', $config->applicationName);
        self::assertSame(['env' => 'prod'], $config->labels->toArray());
        self::assertSame(SpoolAggregationWindow::DEFAULT_SECONDS * 1_000_000_000, $config->aggregationWindow->durationNs);
        self::assertSame(ProfileAccumulator::DEFAULT_MAX_OPEN_BUCKETS, $config->maxOpenBuckets);
    }

    public function testRejectsTooManyOpenBuckets(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The number of open aggregation buckets must be between 1 and 1000, got 0.');

        new ContinuousProfilerConfig('blog', maxOpenBuckets: 0);
    }
}
