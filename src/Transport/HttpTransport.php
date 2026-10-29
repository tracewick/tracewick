<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;

/**
 * POSTs payloads over HTTP/1.1 with hard time limits, using only PHP's socket streams.
 *
 * The whole request, from connecting to reading the status line, must finish within
 * {@see HttpConfig::$timeoutMs}; connecting alone within {@see HttpConfig::$connectTimeoutMs}.
 * After the TCP connection is open the socket is non-blocking and every wait goes through
 * stream_select() with the remaining time, so a destination that accepts the connection and
 * then stalls costs at most the timeout. Name resolution happens before the connect timeout
 * starts and is not bounded by it.
 *
 * Payload parameters become the query string. Only the status code of the response is read:
 * 2xx is delivered; 408, 429 and 5xx are failures worth retrying later; other statuses mean the
 * payload was rejected.
 */
final readonly class HttpTransport implements Transport
{
    private const MAX_STATUS_LINE_BYTES = 8_192;

    /**
     * @param Clock $clock measures the time limits; {@see SystemClock} never goes backwards
     */
    public function __construct(
        private HttpConfig $config,
        private Clock $clock = new SystemClock(),
    ) {}

    public function send(Payload $payload): Delivery
    {
        try {
            return $this->deliver($payload);
        } catch (\Throwable $e) {
            return Delivery::failed($e->getMessage());
        }
    }

    private function deliver(Payload $payload): Delivery
    {
        $deadlineNs = $this->clock->nowNs() + $this->config->timeoutMs * 1_000_000;

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => trim($this->config->host, '[]'),
            'SNI_enabled' => true,
        ]]);
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            \sprintf('tcp://%s:%d', $this->config->host, $this->config->port),
            $errorCode,
            $errorMessage,
            $this->config->connectTimeoutMs / 1_000,
            \STREAM_CLIENT_CONNECT,
            $context,
        );
        if (false === $socket) {
            return Delivery::failed(\sprintf('Cannot connect to %s: %s', $this->config->authority(), '' !== $errorMessage ? $errorMessage : 'unknown error'));
        }

        try {
            stream_set_blocking($socket, false);
            if ($this->config->tls) {
                $this->enableTls($socket, $deadlineNs);
            }

            $this->write($socket, $this->request($payload), $deadlineNs);
            $status = $this->readStatus($socket, $deadlineNs);
        } finally {
            fclose($socket);
        }

        if ($status >= 200 && $status < 300) {
            return Delivery::delivered();
        }

        if (408 === $status || 429 === $status || $status >= 500) {
            return Delivery::failed(\sprintf('HTTP %d from %s', $status, $this->config->authority()));
        }

        return Delivery::rejected(\sprintf('HTTP %d from %s', $status, $this->config->authority()));
    }

    private function request(Payload $payload): string
    {
        $target = $this->config->target;
        if ([] !== $payload->parameters) {
            $target .= (str_contains($target, '?') ? '&' : '?') . http_build_query($payload->parameters, '', '&', \PHP_QUERY_RFC3986);
        }

        $headers = [
            'Host' => $this->config->authority(),
            'User-Agent' => 'tracewick',
            'Content-Type' => str_replace(["\r", "\n"], '', $payload->contentType),
            'Content-Length' => (string) \strlen($payload->body),
            'Connection' => 'close',
        ] + $this->config->headers;

        $head = 'POST ' . $target . " HTTP/1.1\r\n";
        foreach ($headers as $name => $value) {
            $head .= $name . ': ' . $value . "\r\n";
        }

        return $head . "\r\n" . $payload->body;
    }

    /**
     * @param resource $socket
     */
    private function enableTls($socket, int $deadlineNs): void
    {
        while (true) {
            $result = @stream_socket_enable_crypto($socket, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (true === $result) {
                return;
            }

            if (false === $result) {
                throw new TransportException(\sprintf('TLS handshake with %s failed', $this->config->authority()));
            }

            // 0: the handshake needs more data from the server.
            $this->wait($socket, false, $deadlineNs);
        }
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $data, int $deadlineNs): void
    {
        while ('' !== $data) {
            $this->wait($socket, true, $deadlineNs);
            $written = @fwrite($socket, $data);
            if (false === $written) {
                throw new TransportException(\sprintf('Connection to %s closed while sending', $this->config->authority()));
            }

            $data = substr($data, $written);
        }
    }

    /**
     * @param resource $socket
     */
    private function readStatus($socket, int $deadlineNs): int
    {
        $buffer = '';
        while (!str_contains($buffer, "\n")) {
            if (\strlen($buffer) > self::MAX_STATUS_LINE_BYTES) {
                throw new TransportException(\sprintf('Invalid HTTP response from %s', $this->config->authority()));
            }

            $this->wait($socket, false, $deadlineNs);
            $chunk = @fread($socket, self::MAX_STATUS_LINE_BYTES);
            if (false === $chunk || ('' === $chunk && feof($socket))) {
                throw new TransportException(\sprintf('Connection to %s closed before a response', $this->config->authority()));
            }

            $buffer .= $chunk;
        }

        if (1 !== preg_match('#^HTTP/1\.[01] ([1-5]\d\d)[ \r\n]#', $buffer, $matches)) {
            throw new TransportException(\sprintf('Invalid HTTP response from %s', $this->config->authority()));
        }

        return (int) $matches[1];
    }

    /**
     * Waits until the socket is ready, or throws when the deadline passes first.
     *
     * @param resource $socket
     */
    private function wait($socket, bool $forWrite, int $deadlineNs): void
    {
        $remainingUs = intdiv($deadlineNs - $this->clock->nowNs(), 1_000);
        if ($remainingUs <= 0) {
            throw new TransportException(\sprintf('Timed out after %d ms talking to %s', $this->config->timeoutMs, $this->config->authority()));
        }

        $read = $forWrite ? null : [$socket];
        $write = $forWrite ? [$socket] : null;
        $except = null;
        $ready = @stream_select($read, $write, $except, intdiv($remainingUs, 1_000_000), $remainingUs % 1_000_000);
        if (false === $ready) {
            throw new TransportException(\sprintf('Waiting for %s failed', $this->config->authority()));
        }

        if (0 === $ready) {
            throw new TransportException(\sprintf('Timed out after %d ms talking to %s', $this->config->timeoutMs, $this->config->authority()));
        }
    }
}
