# 3. Layered core architecture

- Status: Accepted
- Date: 2026-10-29

## Context

A profiler library has several independent concerns: taking samples, modelling the result,
turning it into a file format, delivering bytes, applying backend rules, and fitting into the
lifecycle of FPM, CLI and long-running workers. Prior art in this space mixes these concerns, which
makes formats hard to test without the extension and backends hard to add without touching the
sampler.

## Decision

The core is split into six layers. Each layer depends only on the layers below it, never on a
layer above:

1. **Sampler** wraps `\ExcimerProfiler` behind an interface (`start`, `stop`, `flush`), so that
   everything above it can be tested without the extension and without reflection.
2. **Profile** is an immutable model of aggregated, weighted stacks with start and end timestamps
   in nanoseconds, the sampling period, the event type (wall or CPU) and labels.
3. **Encoder** turns a Profile into bytes: collapsed stacks, Speedscope, pprof. Encoders are pure
   functions of the Profile.
4. **Transport** delivers bytes somewhere: a file, HTTP through a PSR-18 client, a spool directory,
   a local agent socket. Transports know nothing about formats.
5. **Sink** combines an encoder and a transport with the rules of one backend (for example the
   Pyroscope label syntax and query parameters).
6. **Runtime** owns the lifecycle in a given environment: when to start and stop, request
   sampling, deferred delivery after the response, flushing in workers.

Framework integrations (Symfony, Laravel, PSR-15) live in separate packages and use only the
Runtime and Sink public APIs. Each layer gets its own namespace under `Tracewick\`; a static
analysis rule enforces the dependency direction once the layers exist.

## Consequences

- Encoders are tested with golden files and no extension; transports are tested with fake servers
  and no encoders.
- A new backend is a new Sink, usually without touching other layers.
- More types and indirection than a single profiler class, which the public facade in the Runtime
  layer hides from most users.
