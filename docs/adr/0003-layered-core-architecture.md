# 3. Layered core architecture

- Status: Accepted
- Date: 2026-10-29
- Updated: 2026-11-01

## Context

A profiler library mixes sampling, modelling, encoding, delivery, backend rules and process
lifecycle. Prior art often tangled these, so formats were hard to test without the extension and
backends were hard to add without touching the sampler.

## Decision

Six layers; each depends only on layers below:

1. **Sampler** — `\ExcimerProfiler` behind an interface (`start`, `stop`, `flush`).
2. **Profile** — immutable aggregated stacks, weights, timestamps, period, event type, labels.
3. **Encoder** — Profile → bytes (collapsed, Speedscope, pprof).
4. **Transport** — deliver bytes. Implemented: own HTTP ([ADR 8](0008-own-http-transport.md)),
   spool directory. Proposed: unix socket ([ADR 9](0009-agent-hand-off.md)). Not: a generic
   "file" transport or PSR-18 in the core.
5. **Sink** — encoder + transport + backend rules (`PyroscopeSink`, `SpoolSink`).
6. **Runtime** — when to start/stop, request sampling, flush, drain CLI.

Framework packages (Symfony, Laravel, PSR-15) stay out of this repository and should use Runtime
and Sink APIs only.

`Clock`, `Diagnostics` and `Exception` are shared helpers beside the stack. A static-analysis rule
for layer direction is still desirable; it is not configured yet.

## Consequences

- Encoders can use golden files without the extension; transports can use fake servers.
- A new backend is usually a new Sink.
- More types than a single facade class; Runtime hides most of that from app code.
