<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

use Tracewick\Clock\Clock;

/**
 * Creates the best sampler available in the current process and never throws.
 *
 * Returns an {@see ExcimerSampler} when profiling is enabled and ext-excimer works, and a
 * {@see NullSampler} that records the reason otherwise.
 *
 * Kill-switch precedence (first hit wins): disable-file exists → `$_SERVER` → `$_ENV` →
 * `getenv` → `ini_get('tracewick.enabled')`. Unset or empty means enabled. The disable-file path
 * comes from `TRACEWICK_DISABLE_FILE` using the same source order; its existence is `stat`'d at
 * most once per process.
 */
final class SamplerFactory
{
    /**
     * Environment variable that disables profiling when set to `0`, `false`, `off` or `no`.
     */
    public const ENV_ENABLED = 'TRACEWICK_ENABLED';

    /**
     * Environment variable whose value is a filesystem path; if that path exists, profiling is off.
     */
    public const ENV_DISABLE_FILE = 'TRACEWICK_DISABLE_FILE';

    /**
     * INI key checked last when no environment source set {@see ENV_ENABLED}.
     */
    public const INI_ENABLED = 'tracewick.enabled';

    private const DISABLED_VALUES = ['0', 'false', 'off', 'no'];

    /** @var array<string, bool> */
    private static array $disableFileExists = [];

    /**
     * @param ?array<string, string>                   $environment     variables to read instead of the process
     *                                                                  environment, mainly for tests
     * @param ?bool                                    $extensionLoaded overrides detection of ext-excimer, mainly for tests
     * @param ?array<string, mixed>                    $server          `$_SERVER` override for tests
     * @param ?array<string, mixed>                    $env             `$_ENV` override for tests
     * @param ?\Closure(string): (string|false)        $getenv          `getenv` override for tests
     * @param ?\Closure(string): (string|false)        $iniGet          `ini_get` override for tests
     * @param ?\Closure(string): bool                  $fileExists      `is_file` override for tests
     */
    public function __construct(
        private readonly ?array $environment = null,
        private readonly ?bool $extensionLoaded = null,
        private readonly ?Clock $clock = null,
        private readonly ?array $server = null,
        private readonly ?array $env = null,
        private readonly ?\Closure $getenv = null,
        private readonly ?\Closure $iniGet = null,
        private readonly ?\Closure $fileExists = null,
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
     * Whether the kill switch allows profiling.
     */
    public function isEnabled(): bool
    {
        $disableFile = $this->lookup(self::ENV_DISABLE_FILE);
        if (\is_string($disableFile) && '' !== $disableFile && $this->disableFileExists($disableFile)) {
            return false;
        }

        $enabled = $this->lookup(self::ENV_ENABLED);
        if (null !== $enabled) {
            return !$this->isDisabledValue($enabled);
        }

        $ini = $this->readIni(self::INI_ENABLED);
        if (false === $ini || '' === $ini) {
            return true;
        }

        return !$this->isDisabledValue($ini);
    }

    /**
     * Clears the once-per-process disable-file cache. For tests only.
     *
     * @internal
     */
    public static function resetDisableFileCache(): void
    {
        self::$disableFileExists = [];
    }

    private function disableFileExists(string $path): bool
    {
        if (\array_key_exists($path, self::$disableFileExists)) {
            return self::$disableFileExists[$path];
        }

        $exists = $this->fileExists instanceof \Closure
            ? ($this->fileExists)($path)
            : is_file($path);
        self::$disableFileExists[$path] = $exists;

        return $exists;
    }

    /**
     * Resolves a variable with precedence: constructor `$environment` map (tests) → `$_SERVER` →
     * `$_ENV` → `getenv`.
     */
    private function lookup(string $name): ?string
    {
        if (null !== $this->environment) {
            if (!\array_key_exists($name, $this->environment)) {
                return null;
            }

            return $this->environment[$name];
        }

        $server = $this->server ?? $_SERVER;
        if (\array_key_exists($name, $server) && \is_string($server[$name])) {
            return $server[$name];
        }

        $env = $this->env ?? $_ENV;
        if (\array_key_exists($name, $env) && \is_string($env[$name])) {
            return $env[$name];
        }

        $value = $this->getenv instanceof \Closure ? ($this->getenv)($name) : getenv($name);
        if (!\is_string($value)) {
            return null;
        }

        return $value;
    }

    private function readIni(string $name): string|false
    {
        $value = $this->iniGet instanceof \Closure ? ($this->iniGet)($name) : \ini_get($name);

        return \is_string($value) ? $value : false;
    }

    private function isDisabledValue(string $value): bool
    {
        return \in_array(strtolower(trim($value)), self::DISABLED_VALUES, true);
    }
}
