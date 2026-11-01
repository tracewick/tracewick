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

- **Spool v2** keeps the same outer file layout (magic `TWSP`, versioned JSON header, payload) and
  bumps `version` / `format` so readers can tell v1 from v2. The language-neutral contract stays
  in `docs/spool-format.md` (v2 section to be written with the implementation).
- **v2 payload** is a compact, language-neutral encoding of **stack → weight** (collapsed stacks
  plus a string table for frame names/paths). It is not pprof. Exact field layout is specified
  with the writer PR; this ADR only locks the role of the payload.
- **pprof is produced in the delivery process** (drain or agent), after optional merge by
  aggregation key. Backends still see pprof ([ADR 4](0004-pprof-as-primary-format.md)); only the
  on-disk hand-off changes.
- **v1 is not the final high-load public contract.** Writers move to v2 for production spooling.
  Readers (drain, fixtures, a future agent) MUST keep reading v1 for at least one minor release
  after v2 ships (dual-read), then v1 may be deprecated.
- Collapsed / Speedscope encoders in the library remain for local debugging; they are not the
  spool v2 wire format unless the v2 spec explicitly reuses their bytes (it should not depend on
  PHP `serialize`).

Implementation (writer, dual-read, fixtures) is **not** in the tree yet.

## Consequences

- FPM flush avoids protobuf; drain pays encode cost once per aggregated window.
- Two payload formats to test and fixture; migration must not drop v1 files mid-upgrade.
- Go and PHP readers share one v2 spec and the same fixtures directory layout under
  `tests/Fixtures/spool/`.
- Soft admission, sharding, and lower `max_files` defaults belong with the writer work, not this
  ADR; they assume aggregation + compact files make huge backlogs unnecessary.
