<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

use Tracewick\Clock\Clock;

/**
 * Creates the best sampler available in the current process and never throws.
 *
 * Returns an {@see ExcimerSampler} when profiling is enabled and ext-excimer works, and a
 * {@see NullSampler} that records the reason otherwise.
 */
final readonly class SamplerFactory
{
    /**
     * Environment variable that disables profiling when set to `0`, `false`, `off` or `no`.
     */
    public const ENV_ENABLED = 'TRACEWICK_ENABLED';

    private const DISABLED_VALUES = ['0', 'false', 'off', 'no'];

    /**
     * @param ?array<string, string> $environment variables to read instead of the process
     *                                            environment, mainly for tests
     * @param ?bool                  $extensionLoaded overrides detection of ext-excimer, mainly for tests
     */
    public function __construct(
        private ?array $environment = null,
        private ?bool $extensionLoaded = null,
        private ?Clock $clock = null,
    ) {}

    public function create(SamplerConfig $config = new SamplerConfig()): Sampler
    {
        if (!$this->isEnabled()) {
            return new NullSampler(NullSamplerReason::Disabled, $config, null, $this->clock);
        }

        if (!($this->extensionLoaded ?? \extension_loaded('excimer'))) {
            return new NullSampler(NullSamplerReason::ExtensionMissing, $config, null, $this->clock);
        }

        try {
            return new ExcimerSampler($config, $this->clock);
        } catch (\Throwable $e) {
            return new NullSampler(NullSamplerReason::InitializationFailed, $config, $e, $this->clock);
        }
    }

    /**
     * Whether the kill switch allows profiling. Unset or empty means enabled.
     */
    public function isEnabled(): bool
    {
        $value = null === $this->environment
            ? getenv(self::ENV_ENABLED)
            : $this->environment[self::ENV_ENABLED] ?? false;

        if (false === $value) {
            return true;
        }

        return !\in_array(strtolower(trim($value)), self::DISABLED_VALUES, true);
    }
}
