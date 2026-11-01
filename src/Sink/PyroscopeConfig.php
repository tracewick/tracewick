<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Exception\LogicException;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\HttpConfig;

/**
 * Connection settings for a Grafana Pyroscope server or Grafana Cloud Profiles.
 */
final readonly class PyroscopeConfig
{
    /**
     * @param string               $serverUrl        base URL of the server, such as http://pyroscope:4040
     * @param string               $applicationName  becomes the service_name label of every profile
     * @param ?string              $basicAuthUser    user for HTTP basic authentication, such as the
     *                                               Grafana Cloud stack user id
     * @param ?string              $basicAuthPassword password or access token for basic authentication
     * @param ?string              $tenantId         tenant sent as X-Scope-OrgID to a multi-tenant server
     * @param int                  $connectTimeoutMs time allowed to open the connection
     * @param int                  $timeoutMs        time allowed for the whole request
     * @param CircuitBreakerConfig $circuitBreaker   when to stop sending to a failing server
     *
     * @throws LogicException when a value is invalid
     */
    public function __construct(
        public string $serverUrl,
        public string $applicationName,
        public ?string $basicAuthUser = null,
        public ?string $basicAuthPassword = null,
        public ?string $tenantId = null,
        public int $connectTimeoutMs = HttpConfig::DEFAULT_CONNECT_TIMEOUT_MS,
        public int $timeoutMs = HttpConfig::DEFAULT_TIMEOUT_MS,
        public CircuitBreakerConfig $circuitBreaker = new CircuitBreakerConfig(),
    ) {
        ApplicationName::assertValid($applicationName);

        if ((null === $basicAuthUser) !== (null === $basicAuthPassword)) {
            throw new LogicException('Basic authentication needs both a user and a password.');
        }

        if (null !== $basicAuthUser && str_contains($basicAuthUser, ':')) {
            throw new LogicException('The basic authentication user must not contain a colon.');
        }

        // Validates the URL, the timeouts and the header values.
        $this->httpConfig();
    }

    /**
     * Settings for the HTTP transport that posts to the server's push API.
     */
    public function httpConfig(): HttpConfig
    {
        $headers = [];
        // The constructor guarantees that the password is set whenever the user is.
        if (null !== $this->basicAuthUser) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->basicAuthUser . ':' . $this->basicAuthPassword);
        }

        if (null !== $this->tenantId) {
            $headers['X-Scope-OrgID'] = $this->tenantId;
        }

        return new HttpConfig(
            rtrim($this->serverUrl, '/') . PyroscopePushRequest::PATH,
            $this->connectTimeoutMs,
            $this->timeoutMs,
            $headers,
        );
    }
}
