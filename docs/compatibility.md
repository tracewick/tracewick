# Public API and compatibility

Tracewick 0.x is still pre-1.0. Breaking changes may ship in minor releases; pin a version and
read the migration notes before upgrading. The annotations below identify the intended supported
surface for 1.0. They do not freeze today's API or announce an RC release.

## PHP API boundary

Types marked `@api` are intended for applications and integrations. Their public members are
included, except members marked `@internal` and the internal parameters listed below. `@internal` means
implementation detail even when PHP visibility is public. An undocumented, unmarked new type
must be classified before release; lack of a tag is not an invitation to depend on it.

The following names are relative to `Tracewick\`:

| Area | Intended public types |
| --- | --- |
| Runtime | `Runtime\UnitOfWorkProfiler`, `ContinuousProfiler`, `ProfilerConfig`, `ContinuousProfilerConfig`, `Diagnostics`, `AutoPrepend`, `ProcessProfiler`, `SpoolDrainer`, `SpoolAggregationWindow`, `DrainResult`, `DrainOutcome` |
| Sampling | `Sampler\Sampler`, `SamplerFactory`, `SamplerConfig`, `ExcimerSampler`, `NullSampler`, `NullSamplerReason`, `EventType`, `SampleBatch`, `Sample`, `Frame` |
| Profile model | `Profile\Profile`, `Stack`, `Labels` |
| Sinks | `Sink\Sink`, `SpoolSink`, `PyroscopeSink`, `PyroscopeConfig`, `ApplicationName`, `CardinalityGate`, `SpoolForwarder`, `SpoolFile`, `SpoolFileException` |
| Encoding | `Encoder\Encoder`, `PprofEncoder`, `CollapsedEncoder`, `CollapsedValue`, `SpeedscopeEncoder` |
| Delivery | `Transport\Transport`, `Payload`, `Delivery`, `DeliveryStatus`, `HttpTransport`, `HttpConfig`, `SpoolTransport`, `SpoolConfig`, `SpoolSource`, `DirectorySpoolSource`, `SpoolReaderConfig`, `ClaimedSpoolFile` |
| Circuit breaker | `Transport\CircuitBreakerTransport`, `CircuitBreakerConfig`, `CircuitBreakerStore`, `CircuitBreakerState`, `CircuitBreakerAttempt` |
| DNS | `Transport\DnsResolver`, `SystemDnsResolver`, `CachingDnsResolver` |
| Clock and counters | `Clock\Clock`, `SystemClock`; `Diagnostics\CounterStore`, `CounterStoreFactory`, `ArrayCounterStore`, `NullCounterStore`, `HostCounterKeys`, `DiagnosticLogger` |
| Exceptions | `Exception\TracewickException`, `LogicException` |

Only the first type in each namespace group is written with its namespace; subsequent names in
that group use the same namespace. For example, `Profile\Stack` is part of the profile model,
and `Sampler\Frame` is part of the sampling model.

A custom sampler returns `SampleBatch` / `Sample` / `Frame`; a custom sink receives a `Profile`
and returns `Delivery`. Those model types are part of the extension contract too. A custom
transport, spool source, forwarder, counter store, clock or DNS resolver implements the matching
public interface and follows its documented failure and lifecycle rules.

Native segment-listener wiring is internal. Implementing `Sampler` does not add Excimer's
automatic callback-driven UoW rotation to a custom sampler. Custom integrations must use the
documented runtime calls, including `pulse()` where required; see [runtime.md](runtime.md).

Internal types include bounded builders, stack aggregation/conversion, compact-payload and
protobuf helpers, merge accumulators, aggregation-key and file-name helpers, spool admission
state, concrete shared counter/breaker stores and the drain command implementation. Use the
public factories/interfaces or the drain executable instead of depending on these classes.
The bytes described by the spool specification remain a separate interoperability contract.

### Internal parameters and test seams

These overrides wire internal state or exercise failures deterministically in tests. They are
excluded from the compatibility promise; normal use with their defaults remains supported:

| Member | Excluded parameters |
| --- | --- |
| `UnitOfWorkProfiler::__construct()` | `random`, `registerShutdownFunction` |
| `ContinuousProfiler::__construct()` | `registerShutdownFunction` |
| `SystemClock::__construct()` | `wallNs`, `monotonicNs` |
| `SamplerFactory::__construct()` | `extensionLoaded`, `server`, `env`, `getenv`, `iniGet`, `fileExists` |
| `CounterStoreFactory::create()` | `apcuAvailable` |
| `SpoolTransport::__construct()` | `admission` (internal cache state, not a custom admission-policy interface) |

Methods marked `@internal`, including reset/simulation methods, are also excluded. Clock and
counter-store interfaces, normal configuration arguments and the runtime's `finishRequest`
callback are supported extension points. Merely making a parameter injectable does not turn a
test seam into a production callback API.

## Promise from 1.0

For the public surface, patch and minor releases preserve:

- Type and member names, visibility, static/instance use, public constants and readable fields.
- Parameter names for PHP named arguments, parameter order, accepted values/types, optionality,
  defaults with documented operational meaning, and documented return values/types.
- The ability to implement public interfaces. Adding a required method is a breaking change.
- The documented lifecycle, label filtering, failure handling, delivery outcomes and budgets.
  Invalid configuration may throw during construction; runtime failure handling is documented
  per interface. Fail-open is not a guarantee against native-extension faults or unbounded DNS.
- Existing public enum cases and backed values. Treat public enums as closed: adding a case
  requires a major release because consumers may use exhaustive `match` expressions.

Breaking these contracts requires a major release. An additive optional argument or new API is
allowed only when existing callers, named arguments and implementations keep working. Final
classes are composed, not subclassed. PHP support changes follow [ADR 2](adr/0002-supported-php-versions.md),
including its notice period and minor-release EOL policy.

Bug and security fixes may reject input that was already outside the documented contract. Exact
exception/log/reason text, stack ordering, sample counts affected by scheduling, incidental file
names and byte-for-byte encoder output are not stable APIs. Specified file layout and filename
rules remain required. Encoded profiles must retain their documented meaning and format.

Experimental features and the listed internal parameters/test seams are outside this promise. Automatic OTel trace/span
correlation is indefinitely deferred with no target release and is not an API commitment. The
legacy manually supplied profile-wide `span_id` encoding is not automatic OTel integration;
it does not establish a stable trace-correlation model. Its behavior is unchanged by this work.

## CLI and spool interoperability

The supported command is `vendor/bin/tracewick-drain`; its PHP `DrainCommand` / `DrainSettings`
implementation is internal. Documented option names, environment names, precedence and exit-code
meanings are contracts. Human-readable help and summary wording is not a machine-readable API.

PHP writers, PHP drain and the Go agent communicate through [spool v2 / compact-stacks schema 1](spool-format.md),
not through PHP classes and not by matching package version numbers. Unknown versions/formats
are rejected. Shared fixtures pin the existing format; PHP and Go decode/size limits must be
compatible for the selected configuration. Admission is soft and delivery is at least once.

From 1.0, a supported reader/writer pair must remain interoperable through patch/minor upgrades.
A new writer format requires readers that understand it to be available first, a documented
upgrade order, and an explicit migration/read-compatibility policy before enabling that writer.
Do not silently change v2 bytes or enable a writer that strands the installed reader's files.
The current change introduces no new format, dual reader or automatic migration.

## Compatibility evidence

Snapshot: 2026-11-20. This lists declared ranges and direct evidence separately; it is not a
claim that every possible version combination or platform has been tested.

| Component | Declared range / status | Direct evidence |
| --- | --- | --- |
| Core PHP | `~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0 || ~8.6.0` (8.6 proposed, pending release validation) | PHP 8.2–8.5 PHPUnit matrix and real Excimer stand; 8.6 admission is blocked below |
| PHP 8.6 | Stable PHP; Tracewick support pending | Blocking stand and lowest/highest jobs reserve [Excimer 1.2.8](install-excimer.md#php-86), not yet published. Prior upstream-source probe passed; the future release is unvalidated |
| Excimer | Optional at installation; needed for sampling | Released stand pin 1.2.7 on PHP 8.2–8.5; pending 1.2.8 on 8.6. No dev-source fallback or certification of the future release |
| Pyroscope | Push API and pprof path | Current stand/reference app pin 2.4.3 (dependency update #85); recorded capacity/soak evidence pins 2.4.2. A second major and Grafana Cloud live validation are not established by this stand |
| Symfony bundle | PHP 8.2–8.5; Symfony `^6.4 || ^7.0 || ^8.0`; core `^0.2` | Bundle CI uses Symfony 6.4 / 7.4 / 8.1 across selected PHP versions; runs without Excimer |
| PHP / Go delivery | Spool v2, compact-stacks schema 1 | Shared v2 fixtures and delivery/readback tests; use tested release pairs or recorded source pins |

The bundle's released `^0.2` requirement does not accept core 1.0/RC. Before releasing an RC
pair, run the bridge's existing unit/functional suite against the proposed core source, review
Composer constraints and publish the actual tested package versions/source pins. A green bundle
run using only the released 0.2 dependency does not prove compatibility with a future RC. Do not
invent compatible RC versions before they exist, or require Excimer in bridge CI for this check.

On Linux with Excimer 1.2.7, initialize/start profiling after a worker fork or a fresh `exec`.
Inherited native timer state is not fork-safe; `stop()` and PID detection do not establish
safety. See [runtime.md](runtime.md#forking-workers) and [issue #86](https://github.com/tracewick/tracewick/issues/86).

## Migration for this pre-1.0 change

- `DiagnosticLogger` no longer accepts `directory`; it was ignored. Use
  `new DiagnosticLogger($logger, intervalMs: 1_000, clock: $clock)`. For positional calls, the
  interval is now the second argument and the clock is the third. Suppression stays process-local.
- `SpoolDrainer::drain()` no longer accepts `includeOpenWindows`; it was ignored. Use
  `$drainer->drain(maxFiles: $limit)`. Open windows remain eligible and drain never waits for
  their closure. Remove the old named argument as well as any positional second argument.
- `ApplicationName::assertValid()` is now an intended public Sink API for integrations. Internal
  classifications are explicit; review direct dependencies on helper classes before upgrading.

These are pre-1.0 signature/boundary changes, not a new stable release. Sampling, rotation,
buffering, profile weights, spool bytes and delivery policy are unchanged.
