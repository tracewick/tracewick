<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Psr\Log\LoggerInterface;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Encoder\CompactStacksCodec;
use Tracewick\Encoder\PprofEncoder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Sampler\EventType;
use Tracewick\Transport\CachingDnsResolver;
use Tracewick\Transport\CircuitBreakerTransport;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\HttpTransport;
use Tracewick\Transport\Payload;
use Tracewick\Transport\Transport;

/**
 * Sends profiles to Grafana Pyroscope as pprof through its push API.
 *
 * The application name becomes the service_name series label. Other profile labels travel on the
 * same series (dots in keys become underscores). The pprof payload itself carries a single sample
 * type (wall or cpu in nanoseconds) and, when present, only a span_id sample label. The profile
 * type name on the series is wall or process_cpu.
 *
 * By default payloads go through an {@see HttpTransport} behind a {@see CircuitBreakerTransport}
 * with in-memory state. Set {@see CircuitBreakerConfig::$stateDirectory} when several delivery
 * processes on one host must share one trial. Use this sink from drain, CLI, staging, or
 * long-running workers — not from PHP-FPM request handlers. Under FPM prefer {@see SpoolSink} and
 * `tracewick-drain`, so the worker never waits on the network.
 *
 * @see https://grafana.com/docs/pyroscope/latest/reference-server-api/
 */
final readonly class PyroscopeSink implements Sink, SpoolForwarder
{
    private Transport $transport;

    private PprofEncoder $encoder;

    private PyroscopePushRequest $push;

    /**
     * @param ?Transport       $transport replaces the default HTTP transport and circuit breaker
     * @param ?LoggerInterface $logger    receives the opening and closing of the circuit breaker
     */
    public function __construct(private PyroscopeConfig $config, ?Transport $transport = null, ?LoggerInterface $logger = null)
    {
        $this->transport = $transport ?? $this->defaultTransport($config, $logger);
        $this->encoder = new PprofEncoder();
        $this->push = new PyroscopePushRequest();
    }

    public function send(Profile $profile): Delivery
    {
        try {
            if ($profile->isEmpty()) {
                return Delivery::empty('The profile has no samples.');
            }

            return $this->transport->send($this->payload(
                $this->config->applicationName,
                $profile->eventType,
                $profile->labels->toArray(),
                $this->encoder->encode($profile),
            ));
        } catch (\Throwable $e) {
            return Delivery::failed(\sprintf('Cannot send the profile to Pyroscope: %s', $e->getMessage()));
        }
    }

    /**
     * Sends a profile read from the spool, as a {@see SpoolSink} wrote it.
     *
     * The application name comes from the file, so that one sink can forward the spool of several
     * applications; the configured name is not used. A file that Pyroscope could never accept is
     * reported as rejected.
     */
    public function forward(SpoolFile $file): Delivery
    {
        try {
            if ($file->periodNs < 1 || $file->endNs < $file->startNs) {
                return Delivery::rejected('The spool file has an invalid sampling period or time window.');
            }

            try {
                ApplicationName::assertValid($file->application);
                $body = $this->encodeCompact($file);
            } catch (\Throwable $e) {
                return Delivery::rejected($e->getMessage());
            }

            return $this->transport->send($this->payload(
                $file->application,
                $file->eventType,
                $file->labels,
                $body,
            ));
        } catch (\Throwable $e) {
            return Delivery::failed(\sprintf('Cannot send the profile to Pyroscope: %s', $e->getMessage()));
        }
    }

    private function encodeCompact(SpoolFile $file): string
    {
        $parts = (new CompactStacksCodec())->decode($file->payload);
        $profile = new Profile(
            $file->eventType,
            $file->periodNs,
            $file->startNs,
            $file->endNs,
            $parts->frames,
            $parts->stacks,
            new Labels($file->labels),
        );

        return $this->encoder->encode($profile);
    }

    private function defaultTransport(PyroscopeConfig $config, ?LoggerInterface $logger): Transport
    {
        $http = $config->httpConfig();

        $clock = new SystemClock();

        return new CircuitBreakerTransport(
            new HttpTransport($http, $clock, new CachingDnsResolver(clock: $clock)),
            $config->circuitBreaker,
            $clock,
            $config->circuitBreaker->storeFor($http->url . "\n" . $config->tenantId),
            new DiagnosticLogger($logger, $config->circuitBreaker->stateDirectory),
        );
    }

    /**
     * @param array<string, string> $profileLabels
     */
    private function payload(string $applicationName, EventType $eventType, array $profileLabels, string $rawProfile): Payload
    {
        return new Payload(
            $this->push->encode($applicationName, $eventType, $profileLabels, $rawProfile, $this->push->newSampleId()),
            PyroscopePushRequest::MEDIA_TYPE,
        );
    }
}
