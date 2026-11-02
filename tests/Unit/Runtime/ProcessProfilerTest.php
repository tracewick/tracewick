<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Diagnostics\HostCounterKeys;
use Tracewick\Diagnostics\NullCounterStore;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Profile\LabelPolicy;
use Tracewick\Profile\Labels;
use Tracewick\Runtime\Diagnostics;
use Tracewick\Runtime\ProcessProfiler;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\ExcimerSampler;
use Tracewick\Sampler\ExcimerTraceConverter;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\NullSamplerReason;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Sampler\StackAggregator;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingSink;

#[CoversClass(ProcessProfiler::class)]
#[UsesClass(UnitOfWorkProfiler::class)]
#[UsesClass(ProfilerConfig::class)]
#[UsesClass(Labels::class)]
#[UsesClass(SamplerConfig::class)]
#[UsesClass(SamplerFactory::class)]
#[UsesClass(NullSampler::class)]
#[UsesClass(NullSamplerReason::class)]
#[UsesClass(ExcimerSampler::class)]
#[UsesClass(ExcimerTraceConverter::class)]
#[UsesClass(StackAggregator::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(Diagnostics::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(NullCounterStore::class)]
#[UsesClass(HostCounterKeys::class)]
#[UsesClass(LabelPolicy::class)]
final class ProcessProfilerTest extends TestCase
{
    protected function setUp(): void
    {
        ProcessProfiler::clear();
        UnitOfWorkProfiler::resetInactiveSamplerWarning();
    }

    protected function tearDown(): void
    {
        ProcessProfiler::clear();
    }

    public function testBindGetAndClear(): void
    {
        self::assertNull(ProcessProfiler::get());

        $first = $this->profiler();
        ProcessProfiler::bind($first);
        self::assertSame($first, ProcessProfiler::get());

        ProcessProfiler::clear();
        self::assertNull(ProcessProfiler::get());
    }

    public function testSecondBindIsIgnored(): void
    {
        $first = $this->profiler();
        $second = $this->profiler();

        ProcessProfiler::bind($first);
        ProcessProfiler::bind($second);

        self::assertSame($first, ProcessProfiler::get());
    }

    private function profiler(): UnitOfWorkProfiler
    {
        return new UnitOfWorkProfiler(
            new NullSampler(NullSamplerReason::ExtensionMissing, clock: new ManualClock()),
            new RecordingSink(),
        );
    }
}
