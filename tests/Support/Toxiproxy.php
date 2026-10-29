<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Minimal client for the Toxiproxy HTTP API of the development stand.
 *
 * @see https://github.com/Shopify/toxiproxy#http-api
 */
final readonly class Toxiproxy
{
    public function __construct(private string $apiUrl) {}

    /**
     * Removes every toxic and enables every proxy again.
     */
    public function reset(): void
    {
        $this->request('POST', '/reset');
    }

    public function setEnabled(string $proxy, bool $enabled): void
    {
        $this->request('POST', '/proxies/' . $proxy, ['enabled' => $enabled]);
    }

    /**
     * @param array<string, int> $attributes
     */
    public function addToxic(string $proxy, string $type, string $stream, array $attributes): void
    {
        $this->request('POST', '/proxies/' . $proxy . '/toxics', [
            'name' => $type . '_' . $stream,
            'type' => $type,
            'stream' => $stream,
            'attributes' => $attributes,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $path, array $body = []): void
    {
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($body, \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT),
            'timeout' => 5,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents(rtrim($this->apiUrl, '/') . $path, false, $context);
        $status = $http_response_header[0] ?? 'no response';

        Assert::assertNotFalse($response, \sprintf('Toxiproxy %s %s failed.', $method, $path));
        Assert::assertMatchesRegularExpression('#^HTTP/\S+ 2\d\d#', $status, \sprintf('Toxiproxy %s %s: %s %s', $method, $path, $status, $response));
    }
}
