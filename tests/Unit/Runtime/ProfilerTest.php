<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Runtime\Profiler;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Sampler\ExcimerSampler;
use Tracewick\Sampler\ExcimerTraceConverter;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Tests\Support\RecordingSink;
use Tracewick\Tests\Support\ScriptedSampler;
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
#[UsesClass(ExcimerSampler::class)]
#[UsesClass(ExcimerTraceConverter::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(Delivery::class)]
final class ProfilerTest extends TestCase
{
    private ScriptedSampler $sampler;

    private RecordingSink $sink;

    /** @var list<string> */
    private array $events = [];

    /** @var list<callable(): void> */
    private array $shutdownFunctions = [];

    protected function setUp(): void
    {
        $this->sampler = new ScriptedSampler();
        $this->sink = new RecordingSink();
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

    public function testIgnoresAStartWhileRunning(): void
    {
        $profiler = $this->profiler();
        $this->sampler->willSample('handle');

        $profiler->start(['route' => 'first']);
        $profiler->start(['route' => 'second']);
        $profiler->stop();
        $profiler->flush();

        self::assertSame(['flush', 'start', 'stop', 'flush'], $this->sampler->calls);
        self::assertSame(['route' => 'first'], $this->labelsOf(0));
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
        self::assertSame(0, $profiler->droppedProfiles());
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
        self::assertSame(1, $profiler->droppedProfiles());

        $profiler->flush();

        self::assertSame(['first', 'second'], $this->sink->functions());
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

        $this->sampler->willFail('stop', new \RuntimeException('Cannot stop.'));
        $profiler->stop();

        self::assertSame('Cannot stop.', $profiler->lastFailure()?->getMessage());
        self::assertFalse($profiler->isRunning());
        self::assertSame(0, $profiler->bufferedProfiles());
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
        $profiler = Profiler::create($this->sink);

        $profiler->start();
        self::assertTrue($profiler->isRunning());
        $profiler->stop();

        self::assertFalse($profiler->isRunning());
        self::assertNull($profiler->lastFailure());
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
        );
    }
}
