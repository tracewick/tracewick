# 13. Spool v2: compact stacks on the hot path

- Status: Accepted
- Date: 2026-11-01

## Context

Spool v1 stores a full pprof blob in every file ([ADR 7](0007-language-neutral-spool-format.md),
[ADR 4](0004-pprof-as-primary-format.md)). The FPM worker therefore runs the protobuf encoder on
every flush, and drain re-parses pprof only to forward bytes. Under load that CPU and size cost
sit on the request path we already decided must stay local and cheap ([ADR 5](0005-fail-open-policy.md),
[ADR 10](0010-deferred-delivery.md)). Aggregation ([ADR 12](0012-aggregate-before-delivery.md))
also needs a merge that is trivial on stacks and weights; round-tripping pprof for that is the
wrong shape.

## Decision

- **Spool v2** keeps the same outer file layout (magic `TWSP`, versioned JSON header, payload) with
  `version = 2` and `format = compact-stacks`. The language-neutral contract is
  `docs/spool-format.md`.
- **v2 payload** is a compact, language-neutral encoding of **stack → weight** (collapsed stacks
  plus a string table for frame names/paths). It is not pprof. Exact field layout is in the
  spool-format doc and fixtures.
- **pprof is produced in the delivery process** (drain or agent), after optional merge by
  aggregation key. Backends still see pprof ([ADR 4](0004-pprof-as-primary-format.md)); only the
  on-disk hand-off changes.
- **v1 (pprof-on-disk) is not supported.** Tracewick has no public release and no installed
  readers yet; keeping a dual-read / deprecation window would only add code. Writers and readers
  speak v2 only. Old `version = 1` bytes are rejected.
- Collapsed / Speedscope encoders in the library remain for local debugging; they are not the
  spool wire format.

## Consequences

- FPM flush avoids protobuf; drain pays encode cost once per aggregated window.
- One on-disk payload format to test and fixture under `tests/Fixtures/spool/v2/`.
- Go and PHP readers share one v2 spec.
- Soft admission, sharding, and lower `max_files` defaults belong with the writer work; they
  assume aggregation + compact files make huge backlogs unnecessary.
