# 10. Deferred delivery in the Runtime

- Status: Accepted
- Date: 2026-10-29

## Context

[ADR 5](0005-fail-open-policy.md) keeps network delivery out of the request and leaves the design
of deferred delivery to phase 3. The Runtime layer has to decide when a finished profile is
handed to the sink, how much it may hold in memory meanwhile, and how PHP-FPM keeps the client
from waiting for that hand-off.

In PHP-FPM the client receives the response only when the script ends, shutdown functions
included, unless the script calls `fastcgi_finish_request()`. Symfony and Laravel call it when
they send the response; plain PHP applications usually do not. Anything printed after the call
is discarded, because the connection is already closed.

## Decision

- `Profiler::stop()` only builds the profile and keeps it in a bounded in-memory buffer
  (16 profiles by default). A profile that does not fit is dropped and counted.
- `Profiler::flush()` hands the buffered profiles to the sink. PHP-FPM and console scripts flush
  from a shutdown function registered by `registerShutdown()`; long-running workers flush after
  each unit of work.
- The recommended production sink is `SpoolSink`, so that a flush is a local file write of a few
  microseconds and the network is left to a separate process.
- Tracewick does not call `fastcgi_finish_request()` by default. `ProfilerConfig::finishRequest`
  makes it call the function before the shutdown flush, for applications that want it.

Alternatives considered for `fastcgi_finish_request()`:

- **Never call it.** Safe, but leaves no remedy for a plain PHP application that sends profiles
  straight to Pyroscope: its clients wait for the delivery.
- **Always call it in PHP-FPM.** Clients never wait, but Tracewick would silently change the
  behaviour of the application: output of shutdown functions that run after ours, such as a
  debug toolbar or a logger, would disappear without a trace. That contradicts the promise of
  ADR 5 and would be hard to diagnose.

An opt-in setting keeps the safe behaviour by default and lets the person who knows the
application make the trade-off.

## Consequences

- Nothing is sent while the work runs, and memory held between flushes is bounded.
- A plain PHP application that uses a network sink without `finishRequest` keeps its clients
  waiting for the delivery; the documentation points such setups to `SpoolSink` or the option.
- Profiles still buffered when a process is killed are lost; the buffer is small, so the loss is
  bounded.
