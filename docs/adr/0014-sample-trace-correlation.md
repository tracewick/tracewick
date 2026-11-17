# 14. Capture trace correlation before stack aggregation

- Status: Proposed
- Date: 2026-11-17
- Tracking: [#80](https://github.com/tracewick/tracewick/issues/80)

## Context

Trace correlation is independent of OTLP export ([ADR 6](0006-otlp-profiles-experimental.md)).
The current implementation cannot correlate changing active spans: `Sample` and `Stack` have no
correlation identity, aggregation merges by frames, and `PprofEncoder` copies a profile-wide
`span_id` to every sample. Runtime label policy normally rejects that profile label.
Compact-stacks schema 1 has nowhere to store per-stack correlation.

Reading the current OTel context when a batch is flushed attributes earlier observations to the
context at flush time. Native timestamps cannot reconstruct a context that was never recorded.
Even with correlation, one Excimer observation can carry several timer events after a blocking
C call; it cannot be split into an exact span timeline
([ADR 11](0011-excimer-vm-interrupt-limits.md)).

This proposal changes stack identity and the compact payload. It must be reviewed before the
public API is frozen. It does not implement tracing support or change existing runtime behavior.

## Decision

### Capture at the observation

Opt-in correlation uses a native flush threshold of **one log entry**. The callback reads the
active context immediately after that entry was recorded, before ingesting it into the bounded
aggregator. One entry can still have `event_count > 1`; it is not one callback per timer period.
Correlation-disabled sampling retains its existing batching behavior.

The callback reads only a prepared, supported in-memory context path. Initialization, class
loading and capability checks happen at bootstrap. No exporters, sinks, user loggers, external
counters or span attribute writes run in the callback. Read failures leave the observation
uncorrelated, retain its weight and defer diagnostics. Unexpected multi-entry logs must not be
labelled wholesale with the current span; preserve their weight without correlation instead.

The initial supported scope is sequential execution in PHP-FPM, CLI and RoadRunner workers.
Observations made inside a Fiber remain uncorrelated, without reading OTel context there.
Process-level continuous profiling remains available, but automatic concurrent span correlation
is not promised. Supporting Fibers or Swoole contexts requires a separately verified safe
context path and explicit documentation; a process-global cached span is not a substitute.

OTel API/context support is optional and explicitly enabled. A required SDK, exporter or native
OTel extension is unnecessary for reading context. This proposal adds no runtime dependency.
The integration uses public OTel APIs; replacing global context storage, accessing private
storage fields and changing application error handlers are excluded.

The supported context path must be characterized before implementation is enabled: public API
access alone does not guarantee callback safety for custom context storage or custom span
implementations. Unsupported configurations retain ordinary profiling without correlation and
report the limitation outside the callback. The precise bootstrap capability check is an
implementation gate, not an already proven property of this design.

### Represent correlation separately from series labels

A typed optional correlation identity contains a canonical nonzero 32-character lowercase hex
trace ID and 16-character lowercase hex span ID. The pair is present or absent together.
The OTel adapter uses a valid local active span; a remote parent alone is not local work.
A valid local context need not be recording or sampled. Keeping its identity does not promise
that the tracing backend stores a corresponding span.

The identity travels with `Sample`, the weighted `Stack`, profile construction and both merge
implementations. Stack identity becomes `(frames, truncated, trace ID, span ID)`, including an
explicit absent identity. Equal stacks from different spans must not merge into one correlated
sample. An uncorrelated stack must not inherit a previous observation's identity.

This refines the stack merge rule in [ADR 12](0012-aggregate-before-delivery.md). The delivery
aggregation key remains `(application, series labels, event type, period)`; correlation IDs do
not create separate delivery buckets or spool filename hashes. Whole-profile `span_id` fallback
is removed when the new model is implemented. Profile labels cannot override sample identities.

Existing stack limits apply to the expanded identity, rather than multiplying the limit by the
number of spans. Correlation strings count against string and decoded-memory budgets in PHP and
Go. Admission stays transactional. Excess observations add weight to one uncorrelated
`[overflow]` stack; no unbounded span table is kept beside the bounded builder.
Count correlation lost through overflow or read failure separately from profile delivery drops.
Do not put raw IDs into diagnostic keys or logs. Preserve the weight invariant:
input weight = retained weight + overflow weight.

### Version the compact payload and preserve the identity through delivery

Keep the outer spool version 2 and introduce compact-stacks **payload schema 2**. This is a
separate version byte from the outer `TWSP` version. Schema 1 remains readable and is treated as
uncorrelated. Schema 2 adds trace/span string-table references to every weighted stack, with
paired null sentinels for absent identity. Exact byte layout, validation and shared fixtures must
land in the main repository's spool contract before either writer emits it.

Readers reject malformed, partial and unsupported identities or schemas; they must not silently
discard correlation. Both PHP and Go must charge the additional storage to their existing
allocation and encoding budgets. An uncorrelated writer may continue emitting schema 1; a
correlated profile requires schema 2. Old readers reject schema 2 and can quarantine those files,
so enabling the writer first is not a safe upgrade strategy.

Upgrade and verify readers before enabling correlated writers. For rollback, disable correlation
on writers, drain all schema-2 files with a capable reader, then downgrade readers. Changing the
schema does not change claim/ack/release, at-least-once delivery or soft spool admission.

PHP and Go pprof encoders emit `trace_id` and `span_id` from each weighted stack as sample
labels. Neither ID is a push series label. This extends ADR 4's reserved sample-label set;
arbitrary sample labels are not part of this proposal. Collapsed and Speedscope remain aggregate
views and must document that they do not offer span filtering.

Pyroscope 2.4.2's ingestion code explicitly excludes these two sample labels from series
grouping and preserves their distinction when combining samples
([upstream source](https://github.com/grafana/pyroscope/blob/v2.4.2/pkg/model/pprofsplit/pprof_split.go)).
That source inspection is not an end-to-end validation. A span-filtered live query must show
that the delivered PHP/Go paths retain the expected per-span weights.

### Define what users can navigate

Use the **active span**, not an implicit replacement by the local root span. A parent span's
own profile does not automatically include samples attributed to children. This choice must
appear in the example and tests.

A trace-to-profile example must configure the Grafana/Tempo link and demonstrate the applicable
trace attributes outside the sampler callback. If a `pyroscope.profile.id` attribute is used, it
must match the chosen active-span identity; setting it does not guarantee any samples exist.
Do not introduce an SDK SpanProcessor or mutate spans in the sampler just to create a link.
Any automatic SDK integration needs a separate, explicit dependency/API decision.

### Implementation order and acceptance gates

1. Review this proposal, especially the active-span semantics, initial sequential scope and
   optional OTel capability check. Settle the exact schema-2 contract and shared PHP/Go fixtures.
2. Implement correlation-aware bounded builders, models, codecs, mergers and pprof encoders in
   PHP and Go. Keep collection disabled. Validate schema-1 compatibility, malformed schema-2
   inputs, interleaved correlated/uncorrelated stacks, caps, weight and memory accounting.
3. Implement opt-in native capture and optional OTel integration. Test nested activation and
   restoration, no/invalid/remote/unsampled context, provider failure, Fiber omission, fork,
   automatic UoW rotation and repeated requests on the same worker with real Excimer.
   Preserve no-I/O callback tests and prove no callback-time autoload or warning paths remain.
4. Verify PHP drain and Go-agent delivery with shared fixtures, `go tool pprof` and live
   Pyroscope 2.4.2 span-filtered readback. Add a runnable trace-to-profile example. Bundle tests
   cover Symfony wiring without adding an Excimer requirement to bundle CI.
5. Measure the additional callback and correlation-cardinality overhead in benchmarks at equal
   workloads; test bounded memory and weight preservation under span churn. Validate supported
   PHP/OTel versions. Run the required repository checks before calling the change review-ready.

The current 72h soak remains evidence for its pinned versions with correlation disabled. It
cannot validate this proposed path. Select and run follow-up stability checks for the final
implementation; do not modify or add competing load to the current soak server.

## Consequences

- Correlation survives aggregation without exploding series cardinality, but more distinct
  weighted stacks can reach the same memory cap sooner. Overflow loses correlation explicitly.
- Correlation-enabled sampling invokes PHP more often. Its overhead needs measurement; passing
  correctness probes does not establish acceptable production overhead.
- Concurrent correlation is a documented limitation of the initial scope, not an inferred
  guarantee from Fiber-aware OTel storage.
- Reader-first rollout and coordinated PHP/Go changes are required. This is a pre-1.0 wire/API
  change; no released version gains it merely by accepting the ADR.

Alternatives considered:

- **Read context at delayed flush:** cannot recover earlier active spans.
- **Copy one request/root ID to every sample:** cannot represent changes of active span and
  silently changes the meaning of nested span profiles.
- **Flush on span start/end:** span lifetime is not context activation; it misses detach,
  reactivation and execution-context switches. A SpanProcessor alone is insufficient.
- **Decorate global OTel context storage:** affects application-wide scope and execution-context
  semantics, has bootstrap-order constraints and adds work on every activation, even without a
  profile. Avoid that coupling for the initial sequential integration.
- **Reconstruct from timestamps and a context history:** needs a complete, bounded history of
  activation and switching; Excimer's coalesced events still cannot provide exact sub-intervals.

### Exploratory evidence, not release gates

A local mechanism probe on 2026-11-17 used PHP 8.5.12, Excimer 1.2.7, OTel API 1.11.0
(`bc169d9`) and context 1.6.0 (`711e729`), a 1 ms wall period and depth 48. It exercised
no context, nested/restored local spans, a blocking sleep and two explicitly initialized Fibers
that suspended and resumed. Marked application frames identified the expected context.

With a 10,000-entry threshold, one delayed callback attributed 560 marked entries incorrectly.
With a one-entry threshold, 711 marked entries had no observed mismatches and no multi-entry
logs. A separate read in an uninitialized Fiber emitted an OTel warning. The probe did not
validate all context-transition instructions, custom storage, concurrent runtime support,
production overhead, bounded-model changes or backend delivery. Repeat representative cases as
maintained integration tests when implementing; these exploratory numbers close no RC gate.
