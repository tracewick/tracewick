# 6. OTLP profiles export is experimental

- Status: Accepted
- Date: 2026-10-29
- Updated: 2026-11-18

## Context

OpenTelemetry is adding profiles as a signal. The OTLP profiles protocol and its data model are
still in development, have changed incompatibly between releases, and the OpenTelemetry Collector
supports them only behind the `service.profilesSupport` feature gate. Users will still want to
send profiles to an OpenTelemetry pipeline and to correlate them with traces.

## Decision

- If an OTLP profiles exporter is implemented, it uses the then-current specification and an
  optional (`suggest`) protobuf dependency. The exporter is not implemented or scheduled here.
- It lives in a `Tracewick\Experimental` namespace, is marked `@experimental` in its docblocks,
  and is excluded from the backward compatibility promise, including after 1.0.
- It must be enabled explicitly; no default configuration sends OTLP profiles.
- Trace correlation is separate from the exporter. Automatic trace/span correlation is
  indefinitely deferred, outside RC, without a target release/date or commitment to implement it.
  This includes the request/root-span alternative. It is not part of the stable API promise.
- When the specification becomes stable, a new ADR moves the exporter into the stable API.

## Consequences

- A future experimental exporter can follow spec changes without changing the stable PHP API.
- Current supported production delivery is pprof through Pyroscope's push API; an unimplemented
  exporter or unverified Collector receiver is not an available integration guarantee.

## Implementation status

The previous stable-correlation wording described an unimplemented plan, not an accepted working
API. On 2026-11-18 the maintainer deferred correlation indefinitely. Proposal PR #81 was closed
without merging and issue #80 was closed as not planned; no correlation ADR was accepted.
The existing manually supplied profile-wide `span_id` label is not automatic SDK correlation.
See [the compatibility policy](../compatibility.md) for the intended API boundary. This update
corrects the implementation/status promise; it does not introduce an exporter or correlation.
