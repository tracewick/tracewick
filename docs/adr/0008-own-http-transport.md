# 8. Own HTTP transport instead of PSR-18

- Status: Accepted
- Date: 2026-10-29

## Context

Sinks deliver encoded profiles to backends such as Grafana Pyroscope over HTTP. Two ways to
send a request were considered:

- **A PSR-18 client supplied by the application** (Guzzle, Symfony HttpClient and others).
- **A small transport of our own** on top of PHP stream sockets.

[ADR 5](0005-fail-open-policy.md) requires every network call to have an explicit timeout and
the runtime to never throw. PSR-18 has no notion of timeouts: they live in the client's own
configuration, and popular clients wait forever by default (Guzzle's `timeout` is 0). Tracewick
could document a recommended configuration but could not enforce it. A PSR-18 client also
brings the application's middleware with it: retries with backoff, logging and tracing would
add latency to delivery and instrument the profiler's own requests. Supporting PSR-18 needs
`psr/http-client`, `psr/http-factory` and `psr/http-message`, plus either `php-http/discovery`
or factories passed by the user. Each client behaves differently on errors, so every one of
them would need testing.

Tracewick only ever sends one kind of request: a POST of a binary body, where the status code
is the only part of the response that matters.

## Decision

- The core ships `HttpTransport`, a transport of our own built on `stream_socket_client()`.
  It needs no extension beyond those PHP always has, plus ext-openssl for HTTPS.
- One deadline covers the whole request. Defaults for request-path delivery are 200 ms to connect
  and 1 s for the whole request; drain and other out-of-band senders may raise them. The
  connection is opened with a connect timeout, then the socket is switched to non-blocking mode,
  and the TLS handshake, the write and the read of the status line all wait through
  `stream_select()` against the time left until the deadline. An `EINTR` from `stream_select()` is
  retried; a signal is not treated as a delivery failure.
- Only the status line is read. 2xx means delivered. 408, 429 and 5xx mean transient failures,
  which the circuit breaker counts. 401, 403, 404 and 413 mean a configuration error: the breaker
  opens at once for a longer pause. Any other non-2xx status means the payload was rejected and
  does not change the breaker. Redirects are not followed.
- TLS verifies the peer and its name, with SNI, using PHP's default CA settings.
- A `CircuitBreakerTransport` wraps the HTTP transport, so a backend that keeps failing is not
  contacted again until a cool-down has passed.
- Transports never throw; every failure becomes a `Delivery` result.
- The `Transport` interface stays small, so a PSR-18 adapter can be added later as a separate
  transport, for example in the framework integrations, without changing sinks.

## Consequences

- No runtime dependency, and the fail-open guarantee holds by construction instead of by
  configuration. The behaviour is the same on every installation and is covered by one test
  suite, including chaos tests against Toxiproxy.
- We maintain an HTTP/1.1 client, deliberately kept minimal. Known limits:
  - DNS resolution happens before the connect timeout starts and is not bounded by it. Use an
    IP address or a local resolver cache when this matters.
  - No HTTP proxy support and no client certificates (mTLS).
  - No keep-alive and no HTTP/2: every delivery opens a new connection. Delivery is batched
    and happens outside the request (ADR 5), so the cost is small.
- When users need a proxy, mTLS or their own HTTP stack, a PSR-18 transport is the planned
  extension point, documented then with the timeout configuration it requires.
