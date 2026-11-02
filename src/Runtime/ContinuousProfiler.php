<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\CounterStore;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Diagnostics\HostCounterKeys;
use Tracewick\Diagnostics\NullCounterStore;
use Tracewick\Profile\LabelPolicy;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Sampler\ExcimerSampler;
use Tracewick\Sampler\NullSampler;
use Tracewick\Sampler\NullSamplerReason;
use Tracewick\Sampler\Sampler;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Sink\AggregationCardinalityLimiter;
use Tracewick\Sink\Sink;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;

/**
 * Process-level continuous profiler for long-running runtimes (Swoole, Fibers, ReactPHP).
 *
 * Starts the sampler once with process-level labels from {@see ContinuousProfilerConfig}. It does
 * not accept per-request labels: concurrent requests in one process would otherwise steal each
 * other's samples. Excimer segments (or explicit {@see pulse()} calls) flush into a
 * {@see ProfileAccumulator} keyed like ADR 12; closed windows are sent to the sink.
 *
 * Every method is fail-open (ADR 5). Prefer {@see UnitOfWorkProfiler} for PHP-FPM, CLI, and
 * sequential workers that own one request at a time.
 */
final class ContinuousProfiler
{
    private static bool $inactiveSamplerWarned = false;

    private bool $started = false;

    private bool $shutdownRegistered = false;

    private bool $pulsing = false;

    private int $collectedProfiles = 0;

    private int $deliveredProfiles = 0;

    private int $rejectedProfiles = 0;

    private int $failedProfiles = 0;

    private int $skippedProfiles = 0;

    private int $emptyProfiles = 0;

    private int $swallowedErrors = 0;

    /** @var array<string, true> */
    private static array $policyDroppedKeys = [];

    private ?\Throwable $lastFailure = null;

    private readonly ?NullSamplerReason $inactiveSampler;

    private readonly Clock $clock;

    private readonly ProfileAccumulator $accumulator;

    private readonly Labels $filteredLabels;

    /**
     * @param ?\Closure(callable(): void): void $registerShutdownFunction `register_shutdown_function()` by default
     */
    public function __construct(
        private readonly Sampler $sampler,
        private readonly Sink $sink,
        private readonly ContinuousProfilerConfig $config,
        private readonly DiagnosticLogger $logger = new DiagnosticLogger(),
        ?Clock $clock = null,
        private readonly ?\Closure $registerShutdownFunction = null,
        private readonly CounterStore $counters = new NullCounterStore(),
        private readonly AggregationCardinalityLimiter $cardinalityLimiter = new AggregationCardinalityLimiter(),
    ) {
        $this->inactiveSampler = $sampler instanceof NullSampler ? $sampler->reason : null;
        $this->clock = $clock ?? new SystemClock();
        $this->accumulator = new ProfileAccumulator(
            $config->applicationName,
            $config->aggregationWindow,
            $config->maxOpenBuckets,
        );
        if ($sampler instanceof ExcimerSampler) {
            $sampler->setSegmentListener($this->pulse(...));
        }

        $this->filteredLabels = LabelPolicy::filter($config->labels);
        $this->noteDroppedLabels($config->labels, $this->filteredLabels);
        $this->warnAboutInactiveSampler();
    }

    /**
     * @param ?LoggerInterface $logger receives problems of the profiler, at most once a minute each
     */
    public static function create(
        Sink $sink,
        ContinuousProfilerConfig $config,
        SamplerConfig $samplerConfig = new SamplerConfig(),
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            (new SamplerFactory())->create($samplerConfig),
            $sink,
            $config,
            new DiagnosticLogger($logger),
        );
    }

    /**
     * Starts continuous sampling with the configured process labels. Calling it again is a no-op.
     */
    public function start(): void
    {
        try {
            if ($this->started) {
                return;
            }

            $this->sampler->flush();
            $this->sampler->start();

            if (!$this->inactiveSampler instanceof NullSamplerReason && !$this->sampler->isRunning()) {
                $this->swallow($this->sampler->lastFailure() ?? new \RuntimeException('Tracewick sampler failed to start.'));

                return;
            }

            $this->started = true;
        } catch (\Throwable $e) {
            $this->started = false;
            $this->swallow($e);
        }
    }

    /**
     * Flushes the running sampler into the accumulator and sends closed aggregation windows.
     *
     * Safe to call from a worker loop; also wired to the Excimer flush callback.
     */
    public function pulse(): void
    {
        try {
            if ($this->pulsing || !$this->started) {
                return;
            }

            $this->pulsing = true;
            $this->capture();
            $this->deliver($this->accumulator->drainClosed($this->clock->nowNs()));
        } catch (\Throwable $e) {
            $this->swallow($e);
        } finally {
            $this->pulsing = false;
        }
    }

    /**
     * Captures the current segment and sends every closed window. Open windows stay buffered.
     */
    public function flush(): void
    {
        try {
            if ($this->started) {
                $this->capture();
            }

            $this->deliver($this->accumulator->drainClosed($this->clock->nowNs()));
        } catch (\Throwable $e) {
            $this->swallow($e);
        }
    }

    /**
     * Stops sampling, captures the last segment, and sends every remaining bucket.
     */
    public function stop(): void
    {
        try {
            if (!$this->started) {
                $this->deliver($this->accumulator->drainAll());

                return;
            }

            $this->started = false;
            $this->sampler->stop();
            $this->capture();
            $this->deliver($this->accumulator->drainAll());
        } catch (\Throwable $e) {
            $this->started = false;
            $this->swallow($e);
        }
    }

    /**
     * Registers a shutdown handler that stops and flushes remaining buckets.
     */
    public function registerShutdown(): void
    {
        try {
            if ($this->shutdownRegistered) {
                return;
            }

            $this->shutdownRegistered = true;
            $register = $this->registerShutdownFunction ?? static function (callable $callback): void {
                register_shutdown_function($callback);
            };
            $register($this->stop(...));
        } catch (\Throwable $e) {
            $this->swallow($e);
        }
    }

    public function isRunning(): bool
    {
        return $this->started;
    }

    public function openBuckets(): int
    {
        return $this->accumulator->openBuckets();
    }

    public function diagnostics(): Diagnostics
    {
        return new Diagnostics(
            $this->collectedProfiles,
            $this->accumulator->droppedProfiles(),
            $this->deliveredProfiles,
            $this->rejectedProfiles,
            $this->failedProfiles,
            $this->skippedProfiles,
            $this->emptyProfiles,
            $this->swallowedErrors,
            $this->inactiveSampler,
            $this->sampler->failureCount(),
        );
    }

    public function lastFailure(): ?\Throwable
    {
        return $this->lastFailure;
    }

    /**
     * @internal
     */
    public static function resetInactiveSamplerWarning(): void
    {
        self::$inactiveSamplerWarned = false;
        self::$policyDroppedKeys = [];
    }

    private function noteDroppedLabels(Labels $before, Labels $after): void
    {
        $kept = $after->toArray();
        foreach ($before as $key => $_) {
            if (!\array_key_exists($key, $kept)) {
                $this->noteDroppedKey($key);
            }
        }
    }

    private function noteDroppedKey(string $key): void
    {
        if (isset(self::$policyDroppedKeys[$key])) {
            return;
        }

        self::$policyDroppedKeys[$key] = true;
        $this->logger->log(
            LogLevel::NOTICE,
            'Tracewick dropped label "{key}" because it is denied by the label policy.',
            ['key' => $key],
        );
    }

    private function capture(): void
    {
        $batch = $this->sampler->flush();
        $labels = new Labels($this->cardinalityLimiter->limitLabels(
            $this->config->applicationName,
            $this->filteredLabels->toArray(),
            $batch->eventType,
            $batch->periodNs,
        ));
        $profile = Profile::fromBatch($batch, $labels);
        if ($profile->isEmpty()) {
            ++$this->emptyProfiles;
            $this->counters->increment(HostCounterKeys::PROFILES_EMPTY);

            return;
        }

        ++$this->collectedProfiles;
        $this->counters->increment(HostCounterKeys::PROFILES_COLLECTED);
        $this->accumulator->add($profile);
    }

    /**
     * @param list<Profile> $profiles
     */
    private function deliver(array $profiles): void
    {
        foreach ($profiles as $profile) {
            try {
                $this->record($this->sink->send($profile));
            } catch (\Throwable $e) {
                $this->swallow($e);
            }
        }
    }

    private function record(Delivery $delivery): void
    {
        $context = ['reason' => $delivery->reason];
        switch ($delivery->status) {
            case DeliveryStatus::Delivered:
                ++$this->deliveredProfiles;
                $this->counters->increment(HostCounterKeys::PROFILES_DELIVERED);

                break;

            case DeliveryStatus::Rejected:
            case DeliveryStatus::Configuration:
                ++$this->rejectedProfiles;
                $this->counters->increment(HostCounterKeys::PROFILES_REJECTED);
                $this->logger->log(LogLevel::WARNING, 'The profile backend rejected a profile: {reason}', $context);

                break;

            case DeliveryStatus::Failed:
                ++$this->failedProfiles;
                $this->counters->increment(HostCounterKeys::PROFILES_FAILED);
                $this->logger->log(LogLevel::WARNING, 'Tracewick could not deliver a profile: {reason}', $context);

                break;

            case DeliveryStatus::Skipped:
                ++$this->skippedProfiles;
                $this->counters->increment(HostCounterKeys::PROFILES_SKIPPED);
                $this->logger->log(LogLevel::NOTICE, 'Tracewick skipped a profile: {reason}', $context);

                break;

            case DeliveryStatus::Empty:
                ++$this->emptyProfiles;
                $this->counters->increment(HostCounterKeys::PROFILES_EMPTY);

                break;
        }
    }

    private function swallow(\Throwable $error): void
    {
        ++$this->swallowedErrors;
        $this->counters->increment(HostCounterKeys::PROFILES_SWALLOWED);
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
