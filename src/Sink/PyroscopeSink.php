<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Encoder\PprofEncoder;
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
 * By default payloads go through an {@see HttpTransport} behind a {@see CircuitBreakerTransport}.
 *
 * @see https://grafana.com/docs/pyroscope/latest/configure-server/about-server-api/
 */
final readonly class PyroscopeSink implements Sink
{
    private const SPY_NAME = 'tracewick';

    private Transport $transport;

    private PprofEncoder $encoder;

    public function __construct(private PyroscopeConfig $config, ?Transport $transport = null)
    {
        $this->transport = $transport ?? new CircuitBreakerTransport(new HttpTransport($config->httpConfig()), $config->circuitBreaker);
        $this->encoder = new PprofEncoder();
    }

    public function send(Profile $profile): Delivery
    {
        try {
            if ($profile->isEmpty()) {
                return Delivery::skipped('The profile has no samples.');
            }

            $body = $this->encoder->encode($profile->withLabels($this->labels($profile->labels)));

            return $this->transport->send(new Payload($body, $this->encoder->mediaType(), [
                'name' => $this->config->applicationName,
                'format' => 'pprof',
                // Pyroscope takes whole seconds; round outwards so the window covers the profile.
                'from' => (string) intdiv($profile->startNs, 1_000_000_000),
                'until' => (string) intdiv($profile->endNs + 999_999_999, 1_000_000_000),
                'spyName' => self::SPY_NAME,
                'sampleRate' => (string) max(1, intdiv(1_000_000_000 + intdiv($profile->periodNs, 2), $profile->periodNs)),
            ]));
        } catch (\Throwable $e) {
            return Delivery::failed(\sprintf('Cannot send the profile to Pyroscope: %s', $e->getMessage()));
        }
    }

    private function labels(Labels $labels): Labels
    {
        $mapped = [];
        foreach ($labels as $key => $value) {
            $mapped[str_replace('.', '_', $key)] = $value;
        }

        return new Labels($mapped);
    }
}
