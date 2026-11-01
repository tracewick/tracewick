<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Encoder\Pprof\ProtobufWriter;
use Tracewick\Sampler\EventType;

/**
 * Builds a Pyroscope push.v1 PushRequest that wraps one pprof profile.
 *
 * Series labels carry the profile type ({@see seriesName()}), service_name, the spy name, and the
 * caller's profile labels (with dots mapped to underscores). Sample labels inside the pprof stay
 * empty except for span_id when the encoder includes it.
 *
 * @see https://grafana.com/docs/pyroscope/latest/reference-server-api/
 */
final readonly class PyroscopePushRequest
{
    public const PATH = '/push.v1.PusherService/Push';

    /**
     * Connect unary protobuf codec. Accepted by Grafana Pyroscope and Grafana Cloud Profiles.
     */
    public const MEDIA_TYPE = 'application/proto';

    public const SPY_LABEL = 'pyroscope_spy';

    public const SPY_NAME = 'tracewick';

    private const PUSH_REQUEST_SERIES = 1;
    private const SERIES_LABELS = 1;
    private const SERIES_SAMPLES = 2;
    private const LABEL_NAME = 1;
    private const LABEL_VALUE = 2;
    private const SAMPLE_RAW_PROFILE = 1;
    private const SAMPLE_ID = 2;

    /**
     * @param array<string, string> $profileLabels labels attached to the profile, before key mapping
     */
    public function encode(
        string $applicationName,
        EventType $eventType,
        array $profileLabels,
        string $rawProfile,
        string $sampleId,
    ): string {
        $labels = [
            '__name__' => $this->seriesName($eventType),
            'service_name' => $applicationName,
            self::SPY_LABEL => self::SPY_NAME,
        ];
        foreach ($profileLabels as $key => $value) {
            if ('span_id' === $key) {
                // span_id stays a sample label inside the pprof when the encoder emits it.
                continue;
            }

            $labels[$this->labelKey($key)] = $value;
        }

        $series = new ProtobufWriter();
        foreach ($labels as $name => $value) {
            $series->bytes(self::SERIES_LABELS, (new ProtobufWriter())
                ->bytes(self::LABEL_NAME, $name)
                ->bytes(self::LABEL_VALUE, $value)
                ->toString());
        }

        $series->bytes(self::SERIES_SAMPLES, (new ProtobufWriter())
            ->bytes(self::SAMPLE_RAW_PROFILE, $rawProfile)
            ->bytes(self::SAMPLE_ID, $sampleId)
            ->toString());

        return (new ProtobufWriter())
            ->bytes(self::PUSH_REQUEST_SERIES, $series->toString())
            ->toString();
    }

    /**
     * Grafana profile-type name for the series {@code __name__} label.
     */
    public function seriesName(EventType $eventType): string
    {
        return match ($eventType) {
            EventType::Cpu => 'process_cpu',
            EventType::Wall => 'wall',
        };
    }

    /**
     * RFC 4122 UUID v4 for RawSample.ID.
     */
    public function newSampleId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Pyroscope silently drops label keys that contain dots.
     */
    public function labelKey(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
