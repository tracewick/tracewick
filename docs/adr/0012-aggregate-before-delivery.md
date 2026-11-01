# 12. Aggregate profiles before delivery

- Status: Accepted
- Date: 2026-11-01

## Context

Today each profiled unit of work becomes one spool file and, after drain, one HTTP POST to
Pyroscope. At a few hundred to a thousand requests per second that fills `max_files`, drops
profiles, and floods the backend with tiny pprof bodies. Continuous profiling backends expect
series over time windows, not one upload per request. Aggregation was promised for a future Go
agent; the PHP drain path needs the same rule, or high-load FPM never reaches a stable spool.

## Decision

- **Unit of collection ≠ unit of delivery.** The runtime still profiles one unit of work at a
  time ([ADR 10](0010-deferred-delivery.md)). Delivery merges many units into fewer payloads.
- **Aggregation key:** `(application, labels, event_type, period_ns)`. Label maps are compared
  after sorting keys; values are exact string matches. Two profiles merge only when the key
  matches.
- **Window:** drain (and a future agent) merge files that share a key and whose time windows
  overlap a configurable interval of **10–15 seconds** (default **15 s**). One merged pprof is
  sent per key per window. Appearance latency in Pyroscope is about one window.
- **Where it runs:** the delivery process (`tracewick-drain`, later `tracewick-agent`), not the
  FPM request path. An optional in-process accumulator for long-running workers may follow; it
  must use the same key and window rules.
- **Merge rule:** stacks are keyed by the sequence of frames; each frame by
  `function + file + line` (same identity the Profile model already uses). Weights for equal
  stacks add. Profile `start_ns` / `end_ns` become the min start and max end of the merged set.
  Per-sample timestamps inside one unit of work are not preserved across the merge
  ([ADR 11](0011-excimer-vm-interrupt-limits.md)).
- **Filename hint:** writers put a stable hash of the aggregation key in the spool file name so
  drain can group files without reading headers first. Exact name layout lands with the spool
  writer change; readers that do not understand the hash still work by parsing headers.
- **Cardinality:** a host-wide cap on distinct keys (via the planned `CounterStore`) may collapse
  excess keys into an `other` bucket; that limit is separate work.

Hash-in-name landed with the spool v2 writer; the aggregating drain pipeline follows this contract.

## Consequences

- Spool and HTTP volume drop by roughly the window divided by mean request duration for a given
  key, instead of one-to-one with RPS.
- Flame graphs and Pyroscope series stay meaningful; single-request identity of labels is already
  lost when many requests share the same label set.
- Operators must accept ~10–15 s delay before data appears after a traffic change.
- Drain and a Go agent can share one aggregation spec; inventing a second keying scheme is out of
  scope.
