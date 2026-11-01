<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Runtime\Diagnostics;
use Tracewick\Runtime\Profiler;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Sampler\ExcimerSampler;
use Tracewick\Sampler\ExcimerTraceConverter;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\NullSamplerReason;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingLogger;
use Tracewick\Tests\Support\RecordingSink;
use Tracewick\Tests\Support\ScriptedSampler;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\Delivery;

#[CoversClass(Profiler::class)]
#[UsesClass(ProfilerConfig::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
#[UsesClass(SamplerConfig::class)]
#[UsesClass(SamplerFactory::class)]
#[UsesClass(NullSampler::class)]
#[UsesClass(NullSamplerReason::class)]
#[UsesClass(ExcimerSampler::class)]
#[UsesClass(ExcimerTraceConverter::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Diagnostics::class)]
#[UsesClass(DiagnosticLogger::class)]
#[UsesClass(AtomicFile::class)]
final class ProfilerTest extends TestCase
{
    private ScriptedSampler $sampler;

    private RecordingLogger $logger;

    private TemporaryDirectory $logDirectory;

    private RecordingSink $sink;

    /** @var list<string> */
    private array $events = [];

    /** @var list<callable(): void> */
    private array $shutdownFunctions = [];

    protected function setUp(): void
    {
        Profiler::resetInactiveSamplerWarning();
        $this->sampler = new ScriptedSampler();
        $this->sink = new RecordingSink();
        $this->logger = new RecordingLogger();
        $this->logDirectory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->logDirectory->remove();
    }

    public function testBuffersAProfileUntilTheFlush(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willSample('handle');

        $profiler->start();
        self::assertTrue($profiler->isRunning());
        $profiler->stop();

        self::assertFalse($profiler->isRunning());
        self::assertSame(1, $profiler->bufferedProfiles());
        self::assertSame([], $this->sink->sent);

        $profiler->flush();

        self::assertSame(['handle'], $this->sink->functions());
        self::assertSame(0, $profiler->bufferedProfiles());
        self::assertSame(['flush', 'start', 'stop', 'flush'], $this->sampler->calls);
    }

    public function testDiscardsSamplesTakenBeforeTheStart(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willSample('before')->willSample('during');
        $this->sampler->start();

        $profiler->start();
        $profiler->stop();
        $profiler->flush();

        self::assertSame(['during'], $this->sink->functions());
    }

    public function testCombinesConfiguredAndRequestLabels(): void
    {
        $profiler = $this->profiler(new ProfilerConfig(new Labels(['service' => 'blog', 'env' => 'prod'])));
        $this->sampler->willSample('handle')->willSample('handle');

        $profiler->start(['env' => 'staging', 'route' => 'post_show']);
        $profiler->label('http.method', 'GET');
        $profiler->stop();
        $profiler->start();
        $profiler->stop();
        $profiler->flush();

        self::assertSame(['env' => 'staging', 'http.method' => 'GET', 'route' => 'post_show', 'service' => 'blog'], $this->labelsOf(0));
        self::assertSame(['env' => 'prod', 'service' => 'blog'], $this->labelsOf(1));
    }

    public function testIgnoresAnInvalidLabel(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willSample('handle');

        $profiler->start(['bad key' => 'x', 'route' => 'home']);
        $profiler->label('1st', 'x');
        $profiler->stop();
        $profiler->flush();

        self::assertSame(['route' => 'home'], $this->labelsOf(0));
        self::assertInstanceOf(LogicException::class, $profiler->lastFailure());
    }

    public function testIgnoresLabelsOutsideAProfile(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willSample('handle');

        $profiler->label('route', 'early');
        $profiler->start();
        $profiler->stop();
        $profiler->label('route', 'late');
        $profiler->flush();

        self::assertSame([], $this->labelsOf(0));
    }

    public function testRotatesARunningProfileOnANewStart(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willSample('first')->willSample('second');

        $profiler->start(['route' => 'first']);
        $profiler->start(['route' => 'second']);
        $profiler->stop();
        $profiler->flush();

        self::assertSame(['flush', 'start', 'stop', 'flush', 'flush', 'start', 'stop', 'flush'], $this->sampler->calls);
        self::assertSame(['first', 'second'], $this->sink->functions());
        self::assertSame(['route' => 'first'], $this->labelsOf(0));
        self::assertSame(['route' => 'second'], $this->labelsOf(1));
    }

    public function testSkipsNonStringLabelsWithoutLeavingAStuckProfile(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willSample('handle');

        $profiler->start([0 => 'x', 'route' => 'home', 'nested' => ['a']]);
        self::assertTrue($profiler->isRunning());
        $profiler->stop();
        $profiler->flush();

        self::assertSame(['route' => 'home'], $this->labelsOf(0));
        self::assertSame(2, $profiler->diagnostics()->swallowedErrors);
        self::assertInstanceOf(LogicException::class, $profiler->lastFailure());
    }

    public function testDoesNotMarkStartedWhenTheSamplerFailsQuietly(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willFailQuietly('start', new \RuntimeException('Timer unavailable.'));

        $profiler->start(['route' => 'home']);

        self::assertFalse($profiler->isRunning());
        self::assertSame('Timer unavailable.', $profiler->lastFailure()?->getMessage());
        self::assertSame(1, $profiler->diagnostics()->samplerFailures);
        self::assertSame(1, $profiler->diagnostics()->swallowedErrors);
    }

    public function testIgnoresAStopWithoutAStart(): void
    {
        $profiler = $this->profiler();

        $profiler->stop();

        self::assertSame([], $this->sampler->calls);
        self::assertSame(0, $profiler->bufferedProfiles());
    }

    public function testDropsAProfileWithoutSamples(): void
    {
        $profiler = $this->profiler();

        $profiler->start();
        $profiler->stop();

        self::assertSame(0, $profiler->bufferedProfiles());
        self::assertEquals(new Diagnostics(), $profiler->diagnostics());
    }

    public function testDropsProfilesThatDoNotFitIntoTheBuffer(): void
    {
        $profiler = $this->profiler(new ProfilerConfig(maxBufferedProfiles: 2));
        $this->sampler->willSample('first')->willSample('second')->willSample('third');

        for ($i = 0; $i < 3; ++$i) {
            $profiler->start();
            $profiler->stop();
        }

        self::assertSame(2, $profiler->bufferedProfiles());
        self::assertSame(1, $profiler->diagnostics()->droppedProfiles);

        $profiler->flush();

        self::assertSame(['first', 'second'], $this->sink->functions());
    }

    public function testCountsAndReportsWhatHappensToEachProfile(): void
    {
        $profiler = $this->profiler(new ProfilerConfig(maxBufferedProfiles: 4));
        $this->sink->deliveries = [Delivery::delivered(), Delivery::rejected('Bad request.'), Delivery::failed('Timeout.'), Delivery::skipped('Circuit breaker is open.')];
        for ($i = 0; $i < 6; ++$i) {
            $this->sampler->willSample('handle');
        }

        for ($i = 0; $i < 6; ++$i) {
            $profiler->start();
            $profiler->stop();
        }

        $profiler->start();
        $profiler->stop();
        $profiler->flush();

        self::assertEquals(new Diagnostics(collectedProfiles: 6, droppedProfiles: 2, deliveredProfiles: 1, rejectedProfiles: 1, failedProfiles: 1, skippedProfiles: 1), $profiler->diagnostics());
        self::assertSame(
            [
                'notice: Tracewick dropped a profile because {count} profiles already wait for the flush.',
                'warning: The profile backend rejected a profile: {reason}',
                'warning: Tracewick could not deliver a profile: {reason}',
                'notice: Tracewick skipped a profile: {reason}',
            ],
            $this->logger->lines(),
        );
        self::assertSame(
            [['count' => 4], ['reason' => 'Bad request.'], ['reason' => 'Timeout.'], ['reason' => 'Circuit breaker is open.']],
            array_column($this->logger->records, 'context'),
        );
    }

    public function testCountsAndReportsTheErrorsItSwallows(): void
    {
        $profiler = $this->profiler();

        $profiler->start(['bad key' => 'x']);

        $error = $profiler->lastFailure();
        self::assertInstanceOf(LogicException::class, $error);
        self::assertSame(1, $profiler->diagnostics()->swallowedErrors);
        self::assertSame(['warning: Tracewick ignored an error: {error}'], $this->logger->lines());
        self::assertSame([['error' => $error->getMessage(), 'exception' => $error]], array_column($this->logger->records, 'context'));
    }

    public function testFlushesEveryProfileEvenWhenTheSinkThrows(): void
    {
        $this->sink = new RecordingSink([0]);
        $profiler = $this->profiler();
        $this->sampler->willSample('first')->willSample('second');
        $profiler->start();
        $profiler->stop();
        $profiler->start();
        $profiler->stop();

        $profiler->flush();
        $profiler->flush();

        self::assertSame(['first', 'second'], $this->sink->functions());
        self::assertSame('Send 0 failed.', $profiler->lastFailure()?->getMessage());
    }

    public function testKeepsARunningProfileOutOfTheFlush(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willSample('handle');

        $profiler->start();
        $profiler->flush();

        self::assertSame([], $this->sink->sent);
        self::assertTrue($profiler->isRunning());
    }

    public function testSurvivesASamplerThatThrows(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willFail('start', new \RuntimeException('Cannot start.'));

        $profiler->start();

        self::assertSame('Cannot start.', $profiler->lastFailure()?->getMessage());
        self::assertFalse($profiler->isRunning());
        self::assertSame(1, $profiler->diagnostics()->samplerFailures);
        self::assertSame(0, $profiler->bufferedProfiles());

        $this->sampler->willSample('handle');
        $this->sampler->willFail('stop', new \RuntimeException('Cannot stop.'));
        $profiler->start();
        self::assertTrue($profiler->isRunning());
        $profiler->stop();

        self::assertSame('Cannot stop.', $profiler->lastFailure()?->getMessage());
        self::assertFalse($profiler->isRunning());
        self::assertSame(0, $profiler->bufferedProfiles());
    }

    public function testWarnsOnceWhenTheSamplerIsInactive(): void
    {
        $sampler = new NullSampler(NullSamplerReason::ExtensionMissing, clock: new ManualClock());
        $profiler = new Profiler(
            $sampler,
            $this->sink,
            new ProfilerConfig(),
            null,
            null,
            null,
            new DiagnosticLogger($this->logger, $this->logDirectory->path, clock: new ManualClock()),
        );

        self::assertSame(NullSamplerReason::ExtensionMissing, $profiler->diagnostics()->inactiveSampler);
        self::assertSame(
            ['warning: Tracewick profiling is inactive: ext-excimer is not loaded.'],
            $this->logger->lines(),
        );

        new Profiler(
            new NullSampler(NullSamplerReason::Disabled, clock: new ManualClock()),
            $this->sink,
            logger: new DiagnosticLogger($this->logger, $this->logDirectory->path, clock: new ManualClock()),
        );

        self::assertSame(
            ['warning: Tracewick profiling is inactive: ext-excimer is not loaded.'],
            $this->logger->lines(),
        );
    }

    public function testStopsAndFlushesAtShutdown(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willSample('handle');
        $profiler->start();

        $profiler->registerShutdown();
        $profiler->registerShutdown();

        self::assertCount(1, $this->shutdownFunctions);
        self::assertSame([], $this->sink->sent);

        foreach ($this->shutdownFunctions as $shutdown) {
            $shutdown();
        }

        self::assertFalse($profiler->isRunning());
        self::assertSame(['handle'], $this->sink->functions());
        self::assertSame([], $this->events);
    }

    public function testEndsTheResponseBeforeDeliveringWhenConfigured(): void
    {
        $profiler = $this->profiler(new ProfilerConfig(finishRequest: true));
        $this->sink->onSend[] = function (): void {
            $this->events[] = 'send';
        };
        $this->sampler->willSample('handle');
        $profiler->start();

        $profiler->shutdown();

        self::assertSame(['finish request', 'send'], $this->events);
    }

    public function testDoesNotEndTheResponseWithoutProfiles(): void
    {
        $profiler = $this->profiler(new ProfilerConfig(finishRequest: true));
        $profiler->start();

        $profiler->shutdown();

        self::assertSame([], $this->events);
    }

    public function testDeliversWhenEndingTheResponseFails(): void
    {
        $profiler = new Profiler($this->sampler, $this->sink, new ProfilerConfig(finishRequest: true), static function (): never {
            throw new \RuntimeException('Headers already sent.');
        });
        $this->sampler->willSample('handle');
        $profiler->start();

        $profiler->shutdown();

        self::assertSame(['handle'], $this->sink->functions());
        self::assertSame('Headers already sent.', $profiler->lastFailure()?->getMessage());
    }

    public function testReportsAFailureToRegisterTheShutdown(): void
    {
        $profiler = new Profiler($this->sampler, $this->sink, registerShutdownFunction: static function (): never {
            throw new \RuntimeException('Not allowed.');
        });

        $profiler->registerShutdown();

        self::assertSame('Not allowed.', $profiler->lastFailure()?->getMessage());
    }

    #[TestWith([0.0, 0.5, false])]
    #[TestWith([0.25, 0.25, false])]
    #[TestWith([0.25, 0.24, true])]
    #[TestWith([0.99, 0.98, true])]
    public function testProfilesTheShareOfUnitsOfWorkGivenByTheSampleRate(float $rate, float $random, bool $profiled): void
    {
        $profiler = $this->profiler(new ProfilerConfig(sampleRate: $rate), static fn(): float => $random);
        $this->sampler->willSample('handle');

        $profiler->start(['route' => 'home']);
        self::assertSame($profiled, $profiler->isRunning());
        $profiler->label('route', 'other');
        $profiler->stop();
        $profiler->flush();

        self::assertSame($profiled ? ['handle'] : [], $this->sink->functions());
        self::assertSame($profiled ? ['flush', 'start', 'stop', 'flush'] : [], $this->sampler->calls);
    }

    #[TestWith([0.0])]
    #[TestWith([1.0])]
    public function testDrawsNoRandomNumberForAnAllOrNothingRate(float $rate): void
    {
        $profiler = $this->profiler(new ProfilerConfig(sampleRate: $rate), static function (): never {
            throw new \RuntimeException('No random number expected.');
        });

        $profiler->start();

        self::assertSame(1.0 === $rate, $profiler->isRunning());
        self::assertNull($profiler->lastFailure());
    }

    public function testDrawsAUniformNumberBelowOneByDefault(): void
    {
        mt_srand(7);
        $draw = mt_rand() / (mt_getrandmax() + 1);
        self::assertGreaterThan(0.0, $draw);

        try {
            // The unit of work is profiled only when the draw is below the rate, so a rate equal
            // to the draw and one just above it pin the draw down exactly.
            self::assertFalse($this->startWithSeededDraw($draw));
            self::assertTrue($this->startWithSeededDraw($draw * (1 + 1e-12)));
        } finally {
            mt_srand();
        }
    }

    public function testDecidesForEveryUnitOfWork(): void
    {
        $draws = [0.9, 0.1];
        $profiler = $this->profiler(new ProfilerConfig(sampleRate: 0.5), static function () use (&$draws): float {
            return array_shift($draws) ?? self::fail('Too many random numbers drawn.');
        });
        $this->sampler->willSample('second');

        $profiler->start(['route' => 'first']);
        $profiler->stop();
        $profiler->start(['route' => 'second']);
        $profiler->stop();
        $profiler->flush();

        self::assertSame(['second'], $this->sink->functions());
        self::assertSame(['route' => 'second'], $this->labelsOf(0));
    }

    public function testCreatesAProfilerWithTheAvailableSampler(): void
    {
        $profiler = Profiler::create($this->sink, logger: $this->logger);

        $profiler->start();
        self::assertTrue($profiler->isRunning());
        $profiler->stop();

        self::assertFalse($profiler->isRunning());
        self::assertNull($profiler->lastFailure());
    }

    private function startWithSeededDraw(float $rate): bool
    {
        mt_srand(7);
        $profiler = new Profiler(new ScriptedSampler(), $this->sink, new ProfilerConfig(sampleRate: $rate));
        $profiler->start();

        return $profiler->isRunning();
    }

    /**
     * @return array<string, string>
     */
    private function labelsOf(int $profile): array
    {
        return ($this->sink->sent[$profile] ?? self::fail(\sprintf('Profile %d was not sent.', $profile)))->labels->toArray();
    }

    /**
     * @param ?\Closure(): float $random
     */
    private function profiler(ProfilerConfig $config = new ProfilerConfig(), ?\Closure $random = null): Profiler
    {
        return new Profiler(
            $this->sampler,
            $this->sink,
            $config,
            function (): void {
                $this->events[] = 'finish request';
            },
            /** @param callable(): void $callback */
            function (callable $callback): void {
                $this->shutdownFunctions[] = $callback;
            },
            $random,
            new DiagnosticLogger($this->logger, $this->logDirectory->path, clock: new ManualClock()),
        );
    }
}
