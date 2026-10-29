# 5. Fail-open policy and optional ext-excimer

- Status: Accepted
- Date: 2026-10-29

## Context

Tracewick runs inside the request path of production applications, often on every instance of a
high-load service. A profiler that throws, blocks on a slow backend or leaks memory turns an
observability tool into an outage. The library must also be installable on machines without
ext-excimer: developer laptops, CI images and partial rollouts where the extension is missing on
some hosts.

## Decision

- **Runtime paths never throw into the host application.** Every public entry point used at run
  time (starting, stopping, labelling, flushing, delivering) catches `\Throwable`, counts the
  failure, reports it through a rate-limited PSR-3 logger and diagnostics counters, and returns.
- **Configuration errors fail fast.** Building configuration value objects with invalid values
  throws a `TracewickException` immediately. These calls run at boot or in tests, where a clear
  error is more useful than silent misbehaviour.
- **ext-excimer is optional.** It stays in `suggest`, not `require`. Without it the sampler is a
  no-op implementation, and Tracewick logs one warning per process instead of failing.
- **Kill switch.** `TRACEWICK_ENABLED=0` (environment) disables all work before anything else
  happens, without a deploy.
- **Bounded cost.** Every buffer, queue, label set and network call has an explicit limit or
  timeout. When a limit is reached, data is dropped and counted, never queued without bound.
- **No delivery in the request.** Network delivery happens after the response is sent or in a
  separate process; the design of deferred delivery follows in phase 3.

## Consequences

- Each failure point needs a test proving it does not escape, which adds test code but is the core
  promise of the library.
- Failures are silent for the application, so diagnostics (counters, logs) are first-class
  features, not an afterthought.
- `composer require tracewick/tracewick` succeeds without the extension; documentation must make
  clear that nothing is collected until ext-excimer is installed.
