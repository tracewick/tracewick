<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Sampler\Sampler;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sampler\SamplerFactory;
use Tracewick\Sink\Sink;

/**
 * Profiles units of work, such as requests or jobs, and delivers the profiles after the work is done.
 *
 * {@see start()} begins a profile and {@see stop()} ends it. A finished profile waits in a bounded
 * buffer in memory, so nothing is sent while the work runs; {@see flush()} hands the buffered
 * profiles to the sink. After {@see registerShutdown()} the profiler stops and flushes by itself
 * when PHP shuts down, which is all a PHP-FPM application needs. Long-running workers call
 * {@see flush()} after each unit of work.
 *
 * Every method is safe to call at run time: none of them throws, and a failure only costs the
 * profile it concerns (ADR 5). With a {@see \Tracewick\Sink\SpoolSink} a flush is a local file
 * write; with a sink that sends over the network the work of the flush depends on the backend.
 */
final class Profiler
{
    /** @var list<Profile> */
    private array $buffer = [];

    private Labels $labels;

    private bool $started = false;

    private bool $shutdownRegistered = false;

    private int $droppedProfiles = 0;

    private ?\Throwable $lastFailure = null;

    /** @var \Closure(): void */
    private readonly \Closure $finishRequest;

    /** @var \Closure(callable(): void): void */
    private readonly \Closure $registerShutdownFunction;

    /**
     * @param ?\Closure(): void                 $finishRequest            ends the response to the client; `fastcgi_finish_request()` by default
     * @param ?\Closure(callable(): void): void $registerShutdownFunction `register_shutdown_function()` by default
     */
    public function __construct(
        private readonly Sampler $sampler,
        private readonly Sink $sink,
        private readonly ProfilerConfig $config = new ProfilerConfig(),
        ?\Closure $finishRequest = null,
        ?\Closure $registerShutdownFunction = null,
    ) {
        $this->labels = $config->labels;
        $this->finishRequest = $finishRequest ?? static function (): void {
            if (\function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
        };
        $this->registerShutdownFunction = $registerShutdownFunction ?? static function (callable $callback): void {
            register_shutdown_function($callback);
        };
    }

    /**
     * Creates a profiler with the best sampler available in this process.
     *
     * Without ext-excimer, or with `TRACEWICK_ENABLED=0`, the profiler collects nothing and sends
     * nothing.
     */
    public static function create(Sink $sink, ProfilerConfig $config = new ProfilerConfig(), SamplerConfig $samplerConfig = new SamplerConfig()): self
    {
        return new self((new SamplerFactory())->create($samplerConfig), $sink, $config);
    }

    /**
     * Starts a profile. Calling it while a profile is running has no effect.
     *
     * @param array<string, string> $labels labels of this profile only, such as the route
     */
    public function start(array $labels = []): void
    {
        try {
            if ($this->started) {
                return;
            }

            $this->labels = $this->config->labels;
            $this->started = true;
            foreach ($labels as $key => $value) {
                $this->label($key, $value);
            }

            // Samples taken before the start belong to no profile.
            $this->sampler->flush();
            $this->sampler->start();
        } catch (\Throwable $e) {
            $this->lastFailure = $e;
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
            $this->lastFailure = $e;
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

            if (\count($this->buffer) < $this->config->maxBufferedProfiles) {
                $this->buffer[] = $profile;
            } else {
                ++$this->droppedProfiles;
            }
        } catch (\Throwable $e) {
            $this->lastFailure = $e;
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
                $this->sink->send($profile);
            } catch (\Throwable $e) {
                $this->lastFailure = $e;
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
            $this->lastFailure = $e;
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
                $this->lastFailure = $e;
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

    /**
     * Number of profiles dropped because the buffer was full.
     */
    public function droppedProfiles(): int
    {
        return $this->droppedProfiles;
    }

    /**
     * The last error the profiler swallowed, for diagnostics.
     */
    public function lastFailure(): ?\Throwable
    {
        return $this->lastFailure;
    }
}
