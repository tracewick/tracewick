<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Diagnostics\HostCounterKeys;
use Tracewick\Diagnostics\NullCounterStore;
use Tracewick\Encoder\CompactStacksCodec;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\LabelPiiHeuristics;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Runtime\AutoPrepend;
use Tracewick\Runtime\Diagnostics;
use Tracewick\Runtime\ProcessProfiler;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\ExcimerSampler;
use Tracewick\Sampler\ExcimerTraceConverter;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\NullSamplerReason;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Sampler\StackAggregator;
use Tracewick\Sink\AggregationCardinalityLimiter;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\SpoolAggregationKey;
use Tracewick\Sink\SpoolSink;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\SpoolConfig;
use Tracewick\Transport\SpoolTransport;

#[CoversClass(AutoPrepend::class)]
#[UsesClass(ProcessProfiler::class)]
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
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
#[UsesClass(Frame::class)]
#[UsesClass(EventType::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(Diagnostics::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(NullCounterStore::class)]
#[UsesClass(HostCounterKeys::class)]
#[UsesClass(LabelPiiHeuristics::class)]
#[UsesClass(SpoolSink::class)]
#[UsesClass(SpoolConfig::class)]
#[UsesClass(SpoolTransport::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(LogicException::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(CompactStacksCodec::class)]
#[UsesClass(AggregationCardinalityLimiter::class)]
#[UsesClass(SpoolAggregationKey::class)]
final class AutoPrependTest extends TestCase
{
    private TemporaryDirectory $spool;

    protected function setUp(): void
    {
        ProcessProfiler::clear();
        AutoPrepend::resetWarningsForTests();
        UnitOfWorkProfiler::resetInactiveSamplerWarning();
        $this->spool = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        ProcessProfiler::clear();
        AutoPrepend::resetWarningsForTests();
        $this->spool->remove();
    }

    public function testBootBindsARunningProfiler(): void
    {
        AutoPrepend::boot($this->env(), [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/posts/1',
        ]);

        $profiler = ProcessProfiler::get();
        self::assertNotNull($profiler);
        self::assertTrue($profiler->isRunning());

        $profiler->label('route', 'blog_show');
        $profiler->stop();
        self::assertFalse($profiler->isRunning());
    }

    public function testBootIsIdempotentWhenAlreadyBound(): void
    {
        AutoPrepend::boot($this->env(), ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/a']);
        $first = ProcessProfiler::get();
        self::assertNotNull($first);

        AutoPrepend::boot($this->env([AutoPrepend::ENV_APPLICATION => 'other-app']), [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/b',
        ]);

        self::assertSame($first, ProcessProfiler::get());
    }

    public function testBootNoopsWhenRequiredEnvIsMissing(): void
    {
        AutoPrepend::boot([], ['REQUEST_URI' => '/x']);
        self::assertNull(ProcessProfiler::get());

        AutoPrepend::boot([AutoPrepend::ENV_APPLICATION => 'app'], ['REQUEST_URI' => '/x']);
        self::assertNull(ProcessProfiler::get());

        AutoPrepend::boot([AutoPrepend::ENV_SPOOL_DIR => $this->spool->path], ['REQUEST_URI' => '/x']);
        self::assertNull(ProcessProfiler::get());
    }

    public function testMissingConfigWarningIsEmittedOnce(): void
    {
        $logFile = $this->spool->path . '/error.log';
        $previous = ini_set('error_log', $logFile);

        try {
            AutoPrepend::boot([], []);
            AutoPrepend::boot([], []);
        } finally {
            if (false !== $previous) {
                ini_set('error_log', $previous);
            }
        }

        self::assertFileExists($logFile);
        $logged = (string) file_get_contents($logFile);
        self::assertStringContainsString('TRACEWICK_APPLICATION', $logged);
        self::assertSame(1, substr_count($logged, 'TRACEWICK_APPLICATION'));
    }

    public function testBootSwallowsInvalidApplicationName(): void
    {
        AutoPrepend::boot([
            AutoPrepend::ENV_APPLICATION => 'bad name!',
            AutoPrepend::ENV_SPOOL_DIR => $this->spool->path,
        ], ['REQUEST_URI' => '/']);

        self::assertNull(ProcessProfiler::get());
    }

    public function testBootSwallowsEmptySpoolDirectory(): void
    {
        AutoPrepend::boot([
            AutoPrepend::ENV_APPLICATION => 'app',
            AutoPrepend::ENV_SPOOL_DIR => '',
        ], ['REQUEST_URI' => '/']);

        self::assertNull(ProcessProfiler::get());
    }

    public function testBootAcceptsOptionalEnvAndProjectRoot(): void
    {
        AutoPrepend::boot($this->env([
            AutoPrepend::ENV_ENV => 'staging',
            AutoPrepend::ENV_PROJECT_ROOT => $this->spool->path,
        ]), [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/health',
        ]);

        $profiler = ProcessProfiler::get();
        self::assertNotNull($profiler);
        self::assertTrue($profiler->isRunning());
    }

    #[TestWith(['/posts/1?token=secret', '/posts/1'])]
    #[TestWith(['/only', '/only'])]
    #[TestWith(['', 'unmatched'])]
    #[TestWith(['?token=secret', ''])]
    #[TestWith(['http://example.com', 'http://example.com'])]
    public function testRouteFromServerStripsQueryAndHandlesEmpty(string $uri, string $expected): void
    {
        $server = '' === $uri ? ['REQUEST_METHOD' => 'GET'] : ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => 'GET'];
        self::assertSame($expected, AutoPrepend::routeFromServer($server));
    }

    public function testRouteFromServerReturnsCliWhenNoRequestKeys(): void
    {
        self::assertSame('cli', AutoPrepend::routeFromServer([]));
    }

    public function testBootWithNullEnvironmentUsesProcessVariables(): void
    {
        $appKey = AutoPrepend::ENV_APPLICATION;
        $spoolKey = AutoPrepend::ENV_SPOOL_DIR;
        $previous = [
            'server_app' => $_SERVER[$appKey] ?? null,
            'server_spool' => $_SERVER[$spoolKey] ?? null,
            'env_app' => $_ENV[$appKey] ?? null,
            'env_spool' => $_ENV[$spoolKey] ?? null,
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'uri' => $_SERVER['REQUEST_URI'] ?? null,
        ];

        $_SERVER[$appKey] = 'process-app';
        $_SERVER[$spoolKey] = $this->spool->path;
        $_ENV[$appKey] = 'process-app';
        $_ENV[$spoolKey] = $this->spool->path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/via-process-env';

        try {
            // Defaults → defaultEnvironment() + requestServer() from the real process maps.
            AutoPrepend::boot();
            self::assertNotNull(ProcessProfiler::get());
            self::assertTrue(ProcessProfiler::get()->isRunning());
        } finally {
            $this->restoreProcessValue($appKey, $previous['server_app'], $previous['env_app']);
            $this->restoreProcessValue($spoolKey, $previous['server_spool'], $previous['env_spool']);
            $this->restoreServerKey('REQUEST_METHOD', $previous['method']);
            $this->restoreServerKey('REQUEST_URI', $previous['uri']);
        }
    }

    private function restoreServerKey(string $key, mixed $value): void
    {
        if (null === $value) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $value;
        }
    }

    private function restoreProcessValue(string $key, mixed $server, mixed $env): void
    {
        if (null === $server) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $server;
        }

        if (null === $env) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $env;
        }
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function env(array $extra = []): array
    {
        return $extra + [
            AutoPrepend::ENV_APPLICATION => 'demo-app',
            AutoPrepend::ENV_SPOOL_DIR => $this->spool->path,
        ];
    }
}
