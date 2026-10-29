# 4. pprof as the primary interchange format

- Status: Accepted
- Date: 2026-10-29

## Context

Tracewick needs one format that carries everything the Profile model knows (weighted stacks,
timestamps, period, sample type, labels) to backends and tools. Candidates:

- **Collapsed stacks** (`a;b;c 42`) are simple and human-readable, but carry no timestamps, units
  or labels; Pyroscope's legacy `/ingest` endpoint puts those into query parameters.
- **Speedscope JSON** is good for local viewing, but no profiling backend ingests it.
- **pprof** (gzip-compressed protobuf, `profile.proto`) is accepted by Grafana Pyroscope's push
  API, by the OpenTelemetry Collector's pprof receiver, by `go tool pprof` and by most continuous
  profiling vendors. Its schema has been stable for years.
- **OTLP profiles** are the future standard, but the specification is still changing
  (see [ADR 6](0006-otlp-profiles-experimental.md)).

## Decision

- pprof is the primary format: the format the Profile model maps to without loss, the default for
  Pyroscope delivery, and the payload of the spool format for the agent
  ([ADR 7](0007-language-neutral-spool-format.md)).
- Collapsed stacks and Speedscope are secondary formats for local use, debugging and the legacy
  Pyroscope `/ingest` API.
- The pprof encoder is a small hand-written protobuf writer for the subset of `profile.proto` that
  Tracewick needs, so the core does not depend on `google/protobuf` or the protobuf extension.
- Every encoder has golden-file tests; pprof output is additionally validated with
  `go tool pprof` in CI.

## Consequences

- One lossless format to test thoroughly instead of several equal ones.
- A hand-written encoder is a few hundred lines that we own and must test; in return installation
  needs no extra package or extension.
- If the pprof subset ever proves insufficient, switching the encoder to `google/protobuf` is an
  internal change behind the Encoder interface.
