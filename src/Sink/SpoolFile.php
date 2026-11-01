<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Sampler\EventType;

/**
 * One file of the spool format: a JSON header describing the profile and the payload.
 *
 * The only supported on-disk format is version 2 with a compact stack→weight payload
 * ([ADR 13](../../docs/adr/0013-spool-v2-compact-payload.md)). {@see toBytes()} produces the
 * exact bytes a writer puts in the spool directory; {@see fromBytes()} reads them back.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final readonly class SpoolFile
{
    public const MAGIC = 'TWSP';

    public const VERSION_2 = 2;

    /** @deprecated use VERSION_2 */
    public const VERSION = self::VERSION_2;

    public const FORMAT_COMPACT_STACKS = 'compact-stacks';

    public const MAX_HEADER_BYTES = 1024 * 1024;

    /** Magic, version byte and header length. */
    private const PREAMBLE_BYTES = 9;

    /**
     * @param string                $application application name
     * @param EventType             $eventType   what the sampling period measures
     * @param int                   $periodNs    sampling period in nanoseconds
     * @param int                   $startNs     Unix time in nanoseconds at which the profile window opened
     * @param int                   $endNs       Unix time in nanoseconds at which the profile window closed
     * @param array<string, string> $labels      labels of the whole profile
     * @param string                $format      format of the payload ({@see FORMAT_COMPACT_STACKS})
     * @param string                $payload     the encoded profile
     * @param int                   $version     spool file version ({@see VERSION_2})
     */
    public function __construct(
        public string $application,
        public EventType $eventType,
        public int $periodNs,
        public int $startNs,
        public int $endNs,
        public array $labels,
        public string $format = self::FORMAT_COMPACT_STACKS,
        public string $payload = '',
        public int $version = self::VERSION_2,
    ) {
        if (self::VERSION_2 !== $this->version) {
            throw new SpoolFileException(\sprintf('Spool format version %d is not supported.', $this->version));
        }

        if (self::FORMAT_COMPACT_STACKS !== $this->format) {
            throw new SpoolFileException(\sprintf('Payload format "%s" is not supported.', $this->format));
        }
    }

    /**
     * Reads a spool file.
     *
     * @throws SpoolFileException when the bytes are not a valid version 2 spool file
     */
    public static function fromBytes(string $bytes): self
    {
        if (\strlen($bytes) < self::PREAMBLE_BYTES || !str_starts_with($bytes, self::MAGIC)) {
            throw new SpoolFileException('The file does not start with the spool magic bytes.');
        }

        $version = \ord($bytes[4]);
        if (self::VERSION_2 !== $version) {
            throw new SpoolFileException(\sprintf('Spool format version %d is not supported.', $version));
        }

        /** @var array{1: int} $length */
        $length = unpack('N', $bytes, 5);
        $headerLength = $length[1];
        if ($headerLength > self::MAX_HEADER_BYTES) {
            throw new SpoolFileException(\sprintf('The header of %d bytes exceeds the limit of %d bytes.', $headerLength, self::MAX_HEADER_BYTES));
        }

        if (\strlen($bytes) < self::PREAMBLE_BYTES + $headerLength) {
            throw new SpoolFileException('The file ends inside the header.');
        }

        try {
            $header = json_decode(substr($bytes, self::PREAMBLE_BYTES, $headerLength), true, flags: \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new SpoolFileException(\sprintf('The header is not valid JSON: %s', $e->getMessage()), $e->getCode(), previous: $e);
        }

        if (!\is_array($header) || (array_is_list($header) && [] !== $header)) {
            throw new SpoolFileException('The header is not a JSON object.');
        }

        $payload = substr($bytes, self::PREAMBLE_BYTES + $headerLength);
        $payloadSize = self::integer($header, 'payload_size');
        if (\strlen($payload) !== $payloadSize) {
            throw new SpoolFileException(\sprintf('The payload has %d bytes, the header announces %d.', \strlen($payload), $payloadSize));
        }

        $eventType = EventType::tryFrom(self::string($header, 'event_type'))
            ?? throw new SpoolFileException(\sprintf('Unknown event type "%s".', self::string($header, 'event_type')));
        $periodNs = self::integer($header, 'period_ns');
        if ($periodNs < 1) {
            throw new SpoolFileException('The sampling period must be at least 1 ns.');
        }

        $startNs = self::integer($header, 'start_ns');
        $endNs = self::integer($header, 'end_ns');
        if ($endNs < $startNs) {
            throw new SpoolFileException('The profile window ends before it starts.');
        }

        return new self(
            self::string($header, 'application'),
            $eventType,
            $periodNs,
            $startNs,
            $endNs,
            self::labels($header),
            self::string($header, 'format'),
            $payload,
            $version,
        );
    }

    /**
     * Returns the bytes of the spool file.
     *
     * Strings that are not valid UTF-8 are stored with U+FFFD in place of the invalid bytes.
     *
     * @throws \JsonException when the header cannot be encoded
     */
    public function toBytes(): string
    {
        $header = json_encode([
            'application' => $this->application,
            'event_type' => $this->eventType->value,
            'period_ns' => $this->periodNs,
            'start_ns' => $this->startNs,
            'end_ns' => $this->endNs,
            'labels' => (object) $this->labels,
            'format' => $this->format,
            'payload_size' => \strlen($this->payload),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);

        return self::MAGIC . \chr($this->version) . pack('N', \strlen($header)) . $header . $this->payload;
    }

    /**
     * @param array<mixed> $header
     */
    private static function string(array $header, string $key): string
    {
        $value = $header[$key] ?? null;
        if (!\is_string($value)) {
            throw new SpoolFileException(\sprintf('Header key "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $header
     */
    private static function integer(array $header, string $key): int
    {
        $value = $header[$key] ?? null;
        if (!\is_int($value) || $value < 0) {
            throw new SpoolFileException(\sprintf('Header key "%s" must be a non-negative 64-bit integer.', $key));
        }

        return $value;
    }

    /**
     * @param array<mixed> $header
     *
     * @return array<string, string>
     */
    private static function labels(array $header): array
    {
        $labels = $header['labels'] ?? null;
        if (!\is_array($labels) || (array_is_list($labels) && [] !== $labels)) {
            throw new SpoolFileException('Header key "labels" must be an object.');
        }

        $result = [];
        foreach ($labels as $key => $value) {
            if (!\is_string($key)) {
                throw new SpoolFileException(\sprintf('Label key "%d" must not be a number.', $key));
            }

            if (!\is_string($value)) {
                throw new SpoolFileException(\sprintf('Label "%s" must have a string value.', $key));
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
