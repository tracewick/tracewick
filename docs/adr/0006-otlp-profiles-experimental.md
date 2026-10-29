# 6. OTLP profiles export is experimental

- Status: Accepted
- Date: 2026-10-29

## Context

OpenTelemetry is adding profiles as a signal. The OTLP profiles protocol and its data model are
still in development, have changed incompatibly between releases, and the OpenTelemetry Collector
supports them only behind the `service.profilesSupport` feature gate. Users will still want to
send profiles to an OpenTelemetry pipeline and to correlate them with traces.

## Decision

- An OTLP profiles exporter is built in phase 4 against the current version of the specification,
  using `open-telemetry/gen-otlp-protobuf` as an optional (`suggest`) dependency.
- It lives in a `Tracewick\Experimental` namespace, is marked `@experimental` in its docblocks,
  and is excluded from the backward compatibility promise, including after 1.0.
- It must be enabled explicitly; no default configuration sends OTLP profiles.
- Trace correlation (`trace_id` and `span_id` labels taken from the OpenTelemetry SDK context) is
  separate from the exporter and is part of the stable API, because it works with pprof too.
- When the specification becomes stable, a new ADR moves the exporter into the stable API.

## Consequences

- Tracewick can follow spec changes in minor releases without breaking users of the stable API.
- Users who need a stable path to an OpenTelemetry pipeline use pprof through the Collector's
  pprof receiver until OTLP profiles are stable.
