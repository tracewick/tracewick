# 12. Aggregate profiles before delivery

- Status: Accepted
- Date: 2026-11-01
- Amended: 2026-11-02 (alpha: window = merge compatibility; multi-POST OK)

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
- **Window:** a configurable interval of **10–15 seconds** (default **15 s**) defines which
  files that share a key are **compatible to merge** in one POST. Drain does **not** wait for the
  window to close before sending: it merges what it has claimed, posts, and may post again for the
  same key in the same window (multi-POST OK for alpha). One merged pprof per key per window
  remains a preferred shape when cheap, not an invariant.
- **Where it runs:** the delivery process (`tracewick-drain`, later `tracewick-agent`), not the
  FPM request path. `ContinuousProfiler` also merges in-process with the same key and window
  (`ProfileAccumulator`) before calling the sink, so concurrent workers do not emit one payload
  per Excimer segment. In-process closed-window flushing for ContinuousProfiler is separate from
  the drain send gate (drain has none).
- **Merge rule:** stacks are keyed by the sequence of frames; each frame by
  `function + file + line` (same identity the Profile model already uses). Weights for equal
  stacks add. Profile `start_ns` / `end_ns` become the min start and max end of the merged set.
  Per-sample timestamps inside one unit of work are not preserved across the merge
  ([ADR 11](0011-excimer-vm-interrupt-limits.md)).
- **Filename hint:** writers put a stable hash of the aggregation key in the spool file name so
  drain can group files without reading headers first. Exact name layout lands with the spool
  writer change; readers that do not understand the hash still work by parsing headers.
- **Cardinality:** a host-wide cap on distinct keys via `CounterStore` collapses excess keys into
  `cardinality=other` (`AggregationCardinalityLimiter`, default max 256). Counter names are
  product-prefixed (`tracewick:agg:*`) so a shared APCu or counters directory cannot collide with
  another library.
- **Drain budgets:** each drain pass claims under a byte budget (and a file count), then merges
  and sends; hitting a budget is a normal `LimitReached`, not an error.

Hash-in-name landed with the spool v2 writer; the aggregating drain pipeline and
`ContinuousProfiler`'s accumulator follow this contract.

## Consequences

- Spool and HTTP volume drop when many units share a key, instead of one-to-one with RPS; under
  load the spool can drain while the window is still open.
- Flame graphs and Pyroscope series stay meaningful; single-request identity of labels is already
  lost when many requests share the same label set.
- Appearance latency after a traffic change is bounded by drain interval and batch budgets, not by
  waiting for a 10–15 s window to close.
- Drain and a Go agent can share one aggregation spec; inventing a second keying scheme is out of
  scope.
