# 4. pprof as the primary interchange format

- Status: Accepted
- Date: 2026-10-29
- Updated: 2026-11-02

## Context

Tracewick needs one format that carries weighted stacks, period, sample type and labels to
backends. Options:

- **Collapsed stacks** — simple; no timestamps, units or labels in the payload.
- **Speedscope JSON** — fine for local viewing; backends do not ingest it.
- **pprof** (`profile.proto`) — accepted by Grafana Pyroscope (push and legacy ingest),
  `go tool pprof`, and most continuous profilers. Schema is stable.
- **OTLP profiles** — future standard; still changing ([ADR 6](0006-otlp-profiles-experimental.md)).

## Decision

- pprof is the primary format for the Profile model and for production delivery to Pyroscope.
- **Delivery API:** Pyroscope's push API (`/push.v1.PusherService/Push`). Profile labels become
  series labels on the push request. The pprof payload carries a single sample type
  (`wall` or `cpu` in nanoseconds). Sample labels inside pprof are reserved for `span_id` when
  a tracing model exists; they are not used for series identity.
- Collapsed stacks and Speedscope stay secondary (local use and debugging).
- The encoder is a small hand-written protobuf writer for the subset we need, so the core does not
  depend on `google/protobuf`.
- Output is **uncompressed** protobuf. Callers or transports may gzip when a receiver requires it;
  nothing in the core does that today.
- Every encoder has golden-file tests; checked-in `.pb` goldens are also validated with
  `go tool pprof` in CI.

## Consequences

- One format to test carefully instead of several equal ones.
- Hand-written encoder is a few hundred lines we own and must test.
- Compact spool stores stacks; drain encodes pprof and wraps it in a push request
  ([ADR 7](0007-language-neutral-spool-format.md), [ADR 13](0013-spool-v2-compact-payload.md)).
