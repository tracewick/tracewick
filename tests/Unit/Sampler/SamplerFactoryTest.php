<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sampler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\NullSamplerReason;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Tests\Support\SteppingClock;

#[CoversClass(SamplerFactory::class)]
#[UsesClass(NullSampler::class)]
#[UsesClass(SamplerConfig::class)]
#[UsesClass(SampleBatch::class)]
final class SamplerFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        SamplerFactory::resetDisableFileCache();
    }

    #[DataProvider('disablingValues')]
    public function testKillSwitchDisablesProfiling(string $value): void
    {
        $factory = new SamplerFactory([SamplerFactory::ENV_ENABLED => $value], true, new SteppingClock());

        self::assertFalse($factory->isEnabled());

        $sampler = $factory->create();
        self::assertInstanceOf(NullSampler::class, $sampler);
        self::assertSame(NullSamplerReason::Disabled, $sampler->reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disablingValues(): iterable
    {
        yield 'zero' => ['0'];
        yield 'false' => ['false'];
        yield 'off' => ['off'];
        yield 'no' => ['no'];
        yield 'upper case with spaces' => [' FALSE '];
    }

    #[DataProvider('enablingValues')]
    public function testOtherValuesKeepProfilingEnabled(string $value): void
    {
        self::assertTrue((new SamplerFactory([SamplerFactory::ENV_ENABLED => $value]))->isEnabled());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function enablingValues(): iterable
    {
        yield 'one' => ['1'];
        yield 'true' => ['true'];
        yield 'empty' => [''];
    }

    public function testMissingVariableKeepsProfilingEnabled(): void
    {
        self::assertTrue((new SamplerFactory([]))->isEnabled());
    }

    public function testReadsProcessEnvironmentByDefault(): void
    {
        $previous = getenv(SamplerFactory::ENV_ENABLED);
        putenv(SamplerFactory::ENV_ENABLED . '=0');

        try {
            self::assertFalse((new SamplerFactory())->isEnabled());
        } finally {
            putenv(false === $previous ? SamplerFactory::ENV_ENABLED : SamplerFactory::ENV_ENABLED . '=' . $previous);
        }
    }

    public function testDisableFileWinsOverEnabledEnv(): void
    {
        $factory = new SamplerFactory(
            extensionLoaded: true,
            server: [
                SamplerFactory::ENV_DISABLE_FILE => '/run/tracewick.off',
                SamplerFactory::ENV_ENABLED => '1',
            ],
            fileExists: static fn(string $path): bool => '/run/tracewick.off' === $path,
        );

        self::assertFalse($factory->isEnabled());
    }

    public function testDisableFileIsStatCachedPerProcess(): void
    {
        $stats = 0;
        $factory = new SamplerFactory(
            server: [SamplerFactory::ENV_DISABLE_FILE => '/run/tracewick.off'],
            fileExists: static function () use (&$stats): bool {
                ++$stats;

                return false;
            },
        );

        self::assertTrue($factory->isEnabled());
        self::assertTrue($factory->isEnabled());
        self::assertSame(1, $stats);
    }

    public function testServerEnabledWinsOverEnvAndGetenv(): void
    {
        $factory = new SamplerFactory(
            server: [SamplerFactory::ENV_ENABLED => '0'],
            env: [SamplerFactory::ENV_ENABLED => '1'],
            getenv: static fn(string $name): string|false => SamplerFactory::ENV_ENABLED === $name ? '1' : false,
        );

        self::assertFalse($factory->isEnabled());
    }

    public function testEnvWinsOverGetenv(): void
    {
        $factory = new SamplerFactory(
            server: [],
            env: [SamplerFactory::ENV_ENABLED => '0'],
            getenv: static fn(string $name): string|false => SamplerFactory::ENV_ENABLED === $name ? '1' : false,
        );

        self::assertFalse($factory->isEnabled());
    }

    public function testIniIsCheckedLast(): void
    {
        $factory = new SamplerFactory(
            server: [],
            env: [],
            getenv: static fn(string $name): false => false,
            iniGet: static fn(string $name): string|false => SamplerFactory::INI_ENABLED === $name ? 'off' : false,
        );

        self::assertFalse($factory->isEnabled());
    }

    public function testFallsBackWhenExtensionIsMissing(): void
    {
        $config = new SamplerConfig(eventType: EventType::Cpu);
        $sampler = (new SamplerFactory([], false, new SteppingClock()))->create($config);

        self::assertInstanceOf(NullSampler::class, $sampler);
        self::assertSame(NullSamplerReason::ExtensionMissing, $sampler->reason);
        self::assertNull($sampler->cause);
        self::assertSame(EventType::Cpu, $sampler->flush()->eventType);
    }
}
