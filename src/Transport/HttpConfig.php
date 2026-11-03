<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Exception\LogicException;

/**
 * Where an {@see HttpTransport} sends payloads and how long it may take.
 */
final readonly class HttpConfig
{
    /** Bound for request-path delivery; long enough to open a healthy connection, short enough for FPM. */
    public const DEFAULT_CONNECT_TIMEOUT_MS = 200;

    /** Whole-request bound for request-path delivery (connection included). Drain may raise this. */
    public const DEFAULT_TIMEOUT_MS = 1_000;

    private const MAX_TIMEOUT_MS = 60_000;

    /** Headers the transport sets itself. */
    private const RESERVED_HEADERS = ['host', 'content-length', 'content-type', 'connection', 'transfer-encoding'];

    public bool $tls;

    public string $host;

    public int $port;

    /** Path and query of the URL, at least "/". */
    public string $target;

    /**
     * @param string                $url              http or https URL that payloads are POSTed to
     * @param int                   $connectTimeoutMs time allowed to open the TCP connection
     * @param int                   $timeoutMs        time allowed for the whole request, connection included
     * @param array<string, string> $headers          extra request headers, such as authorization
     *
     * @throws LogicException when the URL, a timeout or a header is invalid
     */
    public function __construct(
        public string $url,
        public int $connectTimeoutMs = self::DEFAULT_CONNECT_TIMEOUT_MS,
        public int $timeoutMs = self::DEFAULT_TIMEOUT_MS,
        public array $headers = [],
    ) {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || !\in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new LogicException(\sprintf('The URL must be an absolute http or https URL, got "%s".', $url));
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new LogicException('The URL must not contain credentials or a fragment; pass credentials as headers.');
        }

        $this->tls = 'https' === strtolower($parts['scheme']);
        // parse_url() keeps the brackets of an IPv6 literal, which stream_socket_client() expects.
        $this->host = $parts['host'];
        $this->port = $parts['port'] ?? ($this->tls ? 443 : 80);
        $this->target = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        if ($connectTimeoutMs < 1 || $connectTimeoutMs > self::MAX_TIMEOUT_MS) {
            throw new LogicException(\sprintf('The connect timeout must be between 1 ms and 60 s, got %d ms.', $connectTimeoutMs));
        }

        if ($timeoutMs < $connectTimeoutMs || $timeoutMs > self::MAX_TIMEOUT_MS) {
            throw new LogicException(\sprintf('The timeout must be between the connect timeout and 60 s, got %d ms.', $timeoutMs));
        }

        foreach ($headers as $name => $value) {
            if (1 !== preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name)) {
                throw new LogicException(\sprintf('Invalid header name "%s".', $name));
            }

            if (\in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                throw new LogicException(\sprintf('The header "%s" is set by the transport.', $name));
            }

            if (1 === preg_match('/[\r\n\0]/', $value)) {
                throw new LogicException(\sprintf('The value of header "%s" contains a line break or NUL byte.', $name));
            }
        }
    }

    /**
     * The Host header value: the port is included only when it is not the scheme's default.
     */
    public function authority(): string
    {
        return $this->port === ($this->tls ? 443 : 80) ? $this->host : $this->host . ':' . $this->port;
    }
}
