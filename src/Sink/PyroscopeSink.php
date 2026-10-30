<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Psr\Log\LoggerInterface;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Encoder\Pprof\LabelKeyRewriter;
use Tracewick\Encoder\Pprof\ProtobufException;
use Tracewick\Encoder\PprofEncoder;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Transport\CircuitBreakerTransport;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\HttpTransport;
use Tracewick\Transport\Payload;
use Tracewick\Transport\Transport;

/**
 * Sends profiles to Grafana Pyroscope as pprof through its /ingest endpoint.
 *
 * The application name becomes the service_name label. Profile labels travel inside the pprof
 * file as sample labels, which Pyroscope turns into series labels without any escaping; dots in
 * label keys become underscores, because Pyroscope silently drops keys that contain dots. The
 * profile type (wall or CPU) comes from the pprof sample types.
 *
 * By default payloads go through an {@see HttpTransport} behind a {@see CircuitBreakerTransport},
 * whose state the processes of a host share per server and tenant, so that all PHP-FPM workers
 * stop sending when the server keeps failing.
 *
 * @see https://grafana.com/docs/pyroscope/latest/configure-server/about-server-api/
 */
final readonly class PyroscopeSink implements Sink, SpoolForwarder
{
    private const SPY_NAME = 'tracewick';

    private Transport $transport;

    private PprofEncoder $encoder;

    /**
     * @param ?Transport       $transport replaces the default HTTP transport and circuit breaker
     * @param ?LoggerInterface $logger    receives the opening and closing of the circuit breaker
     */
    public function __construct(private PyroscopeConfig $config, ?Transport $transport = null, ?LoggerInterface $logger = null)
    {
        $this->transport = $transport ?? $this->defaultTransport($config, $logger);
        $this->encoder = new PprofEncoder();
    }

    public function send(Profile $profile): Delivery
    {
        try {
            if ($profile->isEmpty()) {
                return Delivery::skipped('The profile has no samples.');
            }

            $body = $this->encoder->encode($profile->withLabels($this->labels($profile->labels)));

            return $this->transport->send($this->payload($this->config->applicationName, $body, $profile->startNs, $profile->endNs, $profile->periodNs));
        } catch (\Throwable $e) {
            return Delivery::failed(\sprintf('Cannot send the profile to Pyroscope: %s', $e->getMessage()));
        }
    }

    /**
     * Sends a profile read from the spool, as a {@see SpoolSink} wrote it.
     *
     * The application name comes from the file, so that one sink can forward the spool of several
     * applications; the configured name is not used. Label keys inside the pprof payload are
     * renamed by the same rule as in {@see send()}; when no key needs it, the payload is sent as
     * it is, without being parsed. A file that Pyroscope could never accept is reported as
     * rejected.
     */
    public function forward(SpoolFile $file): Delivery
    {
        try {
            if (SpoolFile::FORMAT_PPROF !== $file->format) {
                return Delivery::rejected(\sprintf('Payload format "%s" cannot be sent to Pyroscope.', $file->format));
            }

            if ($file->periodNs < 1 || $file->endNs < $file->startNs) {
                return Delivery::rejected('The spool file has an invalid sampling period or time window.');
            }

            try {
                ApplicationName::assertValid($file->application);
                // The header lists the labels of every sample, so the payload is parsed only when
                // a key actually needs renaming.
                $body = $this->hasDottedKey($file->labels) ? LabelKeyRewriter::rewrite($file->payload, $this->labelKey(...)) : $file->payload;
            } catch (LogicException|ProtobufException $e) {
                return Delivery::rejected($e->getMessage());
            }

            return $this->transport->send($this->payload($file->application, $body, $file->startNs, $file->endNs, $file->periodNs));
        } catch (\Throwable $e) {
            return Delivery::failed(\sprintf('Cannot send the profile to Pyroscope: %s', $e->getMessage()));
        }
    }

    private function defaultTransport(PyroscopeConfig $config, ?LoggerInterface $logger): Transport
    {
        $http = $config->httpConfig();

        return new CircuitBreakerTransport(
            new HttpTransport($http),
            $config->circuitBreaker,
            new SystemClock(),
            $config->circuitBreaker->sharedStore($http->url . "\n" . $config->tenantId),
            new DiagnosticLogger($logger, $config->circuitBreaker->stateDirectory),
        );
    }

    private function payload(string $applicationName, string $body, int $startNs, int $endNs, int $periodNs): Payload
    {
        return new Payload($body, $this->encoder->mediaType(), [
            'name' => $applicationName,
            'format' => 'pprof',
            // Pyroscope takes whole seconds; round outwards so the window covers the profile.
            'from' => (string) intdiv($startNs, 1_000_000_000),
            'until' => (string) intdiv($endNs + 999_999_999, 1_000_000_000),
            'spyName' => self::SPY_NAME,
            'sampleRate' => (string) max(1, intdiv(1_000_000_000 + intdiv($periodNs, 2), $periodNs)),
        ]);
    }

    private function labels(Labels $labels): Labels
    {
        $mapped = [];
        foreach ($labels as $key => $value) {
            $mapped[$this->labelKey($key)] = $value;
        }

        return new Labels($mapped);
    }

    /**
     * @param array<string, string> $labels
     */
    private function hasDottedKey(array $labels): bool
    {
        foreach (array_keys($labels) as $key) {
            if (str_contains($key, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pyroscope silently drops label keys that contain dots.
     */
    private function labelKey(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
