<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\NullSamplerReason;
use Tracewick\Sampler\Sampler;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Sink\Sink;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;

/**
 * Profiles units of work, such as requests or jobs, and delivers the profiles after the work is done.
 *
 * {@see start()} begins a profile and {@see stop()} ends it. A finished profile waits in a bounded
 * buffer in memory, so nothing is sent while the work runs; {@see flush()} hands the buffered
 * profiles to the sink. After {@see registerShutdown()} the profiler stops and flushes by itself
 * when PHP shuts down, which is all a PHP-FPM application needs. Long-running workers call
 * {@see flush()} after each unit of work.
 *
 * With a sample rate below 1 only that share of units of work is profiled: {@see start()} decides
 * at random, and the profiler stays idle for the others.
 *
 * Every method is safe to call at run time: none of them throws, and a failure only costs the
 * profile it concerns (ADR 5). {@see diagnostics()} counts what happened, and a
 * {@see DiagnosticLogger} reports problems to the application's logger. With a {@see \Tracewick\Sink\SpoolSink} a flush is a local file
 * write; with a sink that sends over the network the work of the flush depends on the backend.
 */
final class Profiler
{
    private static bool $inactiveSamplerWarned = false;

    /** @var list<Profile> */
    private array $buffer = [];

    private Labels $labels;

    private bool $started = false;

    private bool $shutdownRegistered = false;

    private int $collectedProfiles = 0;

    private int $droppedProfiles = 0;

    private int $deliveredProfiles = 0;

    private int $rejectedProfiles = 0;

    private int $failedProfiles = 0;

    private int $skippedProfiles = 0;

    private int $swallowedErrors = 0;

    private ?\Throwable $lastFailure = null;

    private readonly ?NullSamplerReason $inactiveSampler;

    /** @var \Closure(): void */
    private readonly \Closure $finishRequest;

    /** @var \Closure(callable(): void): void */
    private readonly \Closure $registerShutdownFunction;

    /** @var \Closure(): float */
    private readonly \Closure $random;

    /**
     * @param ?\Closure(): void                 $finishRequest            ends the response to the client; `fastcgi_finish_request()` by default
     * @param ?\Closure(callable(): void): void $registerShutdownFunction `register_shutdown_function()` by default
     * @param ?\Closure(): float                $random                   a random number from 0 inclusive to 1 exclusive
     */
    public function __construct(
        private readonly Sampler $sampler,
        private readonly Sink $sink,
        private readonly ProfilerConfig $config = new ProfilerConfig(),
        ?\Closure $finishRequest = null,
        ?\Closure $registerShutdownFunction = null,
        ?\Closure $random = null,
        private readonly DiagnosticLogger $logger = new DiagnosticLogger(),
    ) {
        $this->labels = $config->labels;
        $this->inactiveSampler = $sampler instanceof NullSampler ? $sampler->reason : null;
        $this->finishRequest = $finishRequest ?? static function (): void {
            if (\function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        };
        $this->registerShutdownFunction = $registerShutdownFunction ?? static function (callable $callback): void {
            register_shutdown_function($callback);
        };
        $this->random = $random ?? static fn(): float => mt_rand() / (mt_getrandmax() + 1);
        $this->warnAboutInactiveSampler();
    }

    /**
     * Creates a profiler with the best sampler available in this process.
     *
     * Without ext-excimer, or with `TRACEWICK_ENABLED=0`, the profiler collects nothing and sends
     * nothing.
     *
     * @param ?LoggerInterface $logger receives problems of the profiler, at most once a minute each
     */
    public static function create(
        Sink $sink,
        ProfilerConfig $config = new ProfilerConfig(),
        SamplerConfig $samplerConfig = new SamplerConfig(),
        ?LoggerInterface $logger = null,
    ): self {
        return new self((new SamplerFactory())->create($samplerConfig), $sink, $config, logger: new DiagnosticLogger($logger));
    }

    /**
     * Starts a profile, unless the sample rate leaves this unit of work out.
     *
     * Calling it while a profile is already running ends that profile first (rotate), then starts
     * the new one. Labels are applied before the sampler starts; a non-string key or value is
     * skipped, and an invalid label is ignored. {@see $started} becomes true only after the
     * sampler has started.
     *
     * @param array<mixed, mixed> $labels labels of this profile only, such as the route
     */
    public function start(array $labels = []): void
    {
        try {
            if ($this->started) {
                $this->stop();
            }

            if (!$this->sampled()) {
                return;
            }

            $prepared = $this->prepareLabels($labels);

            // Samples taken before the start belong to no profile.
            $this->sampler->flush();
            $this->sampler->start();

            if (!$this->inactiveSampler instanceof NullSamplerReason && !$this->sampler->isRunning()) {
                $this->swallow($this->sampler->lastFailure() ?? new \RuntimeException('Tracewick sampler failed to start.'));

                return;
            }

            $this->labels = $prepared;
            $this->started = true;
        } catch (\Throwable $e) {
            $this->started = false;
            $this->swallow($e);
        }
    }

    /**
     * Sets a label of the running profile. An invalid label is ignored.
     */
    public function label(string $key, string $value): void
    {
        try {
            if ($this->started) {
                $this->labels = $this->labels->with($key, $value);
            }
        } catch (\Throwable $e) {
            $this->swallow($e);
        }
    }

    /**
     * Ends the running profile and keeps it for the next flush. A profile without samples, or one
     * that does not fit into the buffer, is dropped.
     */
    public function stop(): void
    {
        try {
            if (!$this->started) {
                return;
            }

            $this->started = false;
            $this->sampler->stop();
            $profile = Profile::fromBatch($this->sampler->flush(), $this->labels);
            if ($profile->isEmpty()) {
                return;
            }

            ++$this->collectedProfiles;
            if (\count($this->buffer) < $this->config->maxBufferedProfiles) {
                $this->buffer[] = $profile;
            } else {
                ++$this->droppedProfiles;
                $this->logger->log(LogLevel::NOTICE, 'Tracewick dropped a profile because {count} profiles already wait for the flush.', ['count' => \count($this->buffer)]);
            }
        } catch (\Throwable $e) {
            $this->started = false;
            $this->swallow($e);
        }
    }

    /**
     * Sends the buffered profiles to the sink and empties the buffer. A running profile is not
     * affected.
     */
    public function flush(): void
    {
        $profiles = $this->buffer;
        $this->buffer = [];
        foreach ($profiles as $profile) {
            try {
                $this->record($this->sink->send($profile));
            } catch (\Throwable $e) {
                $this->swallow($e);
            }
        }
    }

    /**
     * Makes PHP stop the running profile and flush when it shuts down. Calling it again has no effect.
     */
    public function registerShutdown(): void
    {
        try {
            if (!$this->shutdownRegistered) {
                $this->shutdownRegistered = true;
                ($this->registerShutdownFunction)($this->shutdown(...));
            }
        } catch (\Throwable $e) {
            $this->swallow($e);
        }
    }

    /**
     * Stops the running profile and flushes, ending the response first when configured to.
     *
     * Called by PHP at shutdown after {@see registerShutdown()}.
     */
    public function shutdown(): void
    {
        $this->stop();
        if ([] === $this->buffer) {
            return;
        }

        if ($this->config->finishRequest) {
            try {
                ($this->finishRequest)();
            } catch (\Throwable $e) {
                $this->swallow($e);
            }
        }

        $this->flush();
    }

    public function isRunning(): bool
    {
        return $this->started;
    }

    /**
     * Number of profiles waiting for the next flush.
     */
    public function bufferedProfiles(): int
    {
        return \count($this->buffer);
    }

    public function diagnostics(): Diagnostics
    {
        return new Diagnostics(
            $this->collectedProfiles,
            $this->droppedProfiles,
            $this->deliveredProfiles,
            $this->rejectedProfiles,
            $this->failedProfiles,
            $this->skippedProfiles,
            $this->swallowedErrors,
            $this->inactiveSampler,
            $this->sampler->failureCount(),
        );
    }

    /**
     * The last error the profiler swallowed, for diagnostics.
     */
    public function lastFailure(): ?\Throwable
    {
        return $this->lastFailure;
    }

    /**
     * Resets the once-per-process inactive-sampler warning. For tests only.
     *
     * @internal
     */
    public static function resetInactiveSamplerWarning(): void
    {
        self::$inactiveSamplerWarned = false;
    }

    /**
     * @param array<mixed, mixed> $labels
     */
    private function prepareLabels(array $labels): Labels
    {
        $prepared = $this->config->labels;
        foreach ($labels as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                $this->swallow(new LogicException('Profile labels must be string keys and string values.'));

                continue;
            }

            try {
                $prepared = $prepared->with($key, $value);
            } catch (\Throwable $e) {
                $this->swallow($e);
            }
        }

        return $prepared;
    }

    /**
     * Whether the next unit of work is profiled. The random number is only drawn for a rate
     * between 0 and 1.
     */
    private function sampled(): bool
    {
        $rate = $this->config->sampleRate;

        return $rate >= 1.0 || ($rate > 0.0 && ($this->random)() < $rate);
    }

    private function record(Delivery $delivery): void
    {
        $context = ['reason' => $delivery->reason];
        switch ($delivery->status) {
            case DeliveryStatus::Delivered:
                ++$this->deliveredProfiles;

                break;

            case DeliveryStatus::Rejected:
                ++$this->rejectedProfiles;
                $this->logger->log(LogLevel::WARNING, 'The profile backend rejected a profile: {reason}', $context);

                break;

            case DeliveryStatus::Failed:
                ++$this->failedProfiles;
                $this->logger->log(LogLevel::WARNING, 'Tracewick could not deliver a profile: {reason}', $context);

                break;

            case DeliveryStatus::Skipped:
                ++$this->skippedProfiles;
                $this->logger->log(LogLevel::NOTICE, 'Tracewick skipped a profile: {reason}', $context);

                break;
        }
    }

    private function swallow(\Throwable $error): void
    {
        ++$this->swallowedErrors;
        $this->lastFailure = $error;
        $this->logger->log(LogLevel::WARNING, 'Tracewick ignored an error: {error}', ['error' => $error->getMessage(), 'exception' => $error]);
    }

    private function warnAboutInactiveSampler(): void
    {
        if (!$this->inactiveSampler instanceof NullSamplerReason || self::$inactiveSamplerWarned) {
            return;
        }

        self::$inactiveSamplerWarned = true;
        $message = match ($this->inactiveSampler) {
            NullSamplerReason::Disabled => 'Tracewick profiling is disabled (TRACEWICK_ENABLED).',
            NullSamplerReason::ExtensionMissing => 'Tracewick profiling is inactive: ext-excimer is not loaded.',
            NullSamplerReason::InitializationFailed => 'Tracewick profiling is inactive: ExcimerProfiler failed to initialize.',
        };
        $context = ['reason' => $this->inactiveSampler->value];
        if ($this->sampler instanceof NullSampler && $this->sampler->cause instanceof \Throwable) {
            $context['error'] = $this->sampler->cause->getMessage();
            $context['exception'] = $this->sampler->cause;
        }

        if ($this->logger->hasLogger()) {
            $this->logger->log(LogLevel::WARNING, $message, $context);
        } else {
            $suffix = isset($context['error']) ? ': ' . $context['error'] : '';
            error_log($message . $suffix);
        }
    }
}
