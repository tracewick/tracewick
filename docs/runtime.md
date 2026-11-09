# Profiling requests and jobs

Tracewick exposes two runtime adapters:

| Adapter | Use when |
| --- | --- |
| [`UnitOfWorkProfiler`](#unit-of-work-profiler) | PHP-FPM, CLI, sequential workers (one request or job at a time) |
| [`ContinuousProfiler`](#continuous-profiler) | Swoole, Fibers, ReactPHP and similar: one process, concurrent work |

Both are fail-open ([ADR 5](adr/0005-fail-open-policy.md)): no method throws into the host
application.

## Unit of work profiler

`Tracewick\Runtime\UnitOfWorkProfiler` profiles one unit of work at a time, such as an HTTP
request, a console command or a queue job, and delivers the profiles after the work is done, never
while it runs.

```php
use Tracewick\Profile\Labels;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

$profiler = UnitOfWorkProfiler::create(
    new SpoolSink('blog', new SpoolConfig('/var/spool/tracewick')),
    new ProfilerConfig(new Labels(['env' => 'prod'])),
);
```

`UnitOfWorkProfiler::create()` picks the best sampler for the process. Without ext-excimer, or when
the kill switch disables profiling, the profiler collects and sends nothing, and the application
runs as usual. The kill switch precedence is: `TRACEWICK_DISABLE_FILE` path exists →
`TRACEWICK_ENABLED` in `$_SERVER` → `$_ENV` → `getenv` → `ini_get('tracewick.enabled')`. Disabling
values are `0`, `false`, `off`, and `no`. The first profiler constructed in the process logs a
warning once (to the PSR-3 logger when given, otherwise to `error_log`) that names the reason:
disabled, missing extension, or init failure.

When constructing `SamplerConfig` yourself, set `projectRoot` to the application base path so frame
files and closure names do not include release-directory prefixes (otherwise each deploy splits
aggregation series).

### The lifecycle

| Call                     | What it does                                                                 |
|--------------------------|------------------------------------------------------------------------------|
| `start(['route' => …])`  | Starts sampling; the labels apply to this profile only. A second `start()` ends the running profile first (rotate). |
| `label('route', …)`      | Sets a label of the running profile, for values known only later            |
| `stop()`                 | Stops sampling and keeps the profile in a buffer in memory                  |
| `flush()`                | Hands the buffered profiles to the sink                                      |
| `registerShutdown()`     | Makes PHP call `stop()` and `flush()` when it shuts down                     |

None of these methods throws. Non-string or invalid labels are ignored, a failure costs only the
profile it concerns, and `lastFailure()` returns the last error swallowed. `start()` marks the
profiler running only after the sampler has started. See [Diagnostics](#diagnostics).

The buffer holds `maxBufferedProfiles` profiles, 16 by default. A profile that does not fit is
dropped and counted; it is never sent from inside the work.

### Profiling a share of requests

Every profiled request pays for sampling and for building and storing its profile. On a busy
service a share of the requests gives the same flame graphs at a fraction of that cost:

```php
new ProfilerConfig(new Labels(['env' => 'prod']), sampleRate: 0.1); // one request in ten
```

`start()` decides at random for each unit of work (`random_int`, not the app's `mt_rand` state).
For the others it does nothing: the sampler does not run, and `label()` and `stop()` have no
effect. The default rate is **0.1** (one unit in ten); pass `sampleRate: 1.0` only when you
intentionally want every request. `0` turns profiling off without removing the calls. On
construct the profiler logs the effective rate once via `error_log`.

The profiles show only the requests that were profiled, so absolute totals in Pyroscope shrink
by the rate. Shares within a flame graph, which is what profiling is read for, stay the same.

### Which sink

Delivery happens in `flush()`, after the work, but in the same process. With a `SpoolSink` it is a
local file write (cheap next to encoding and HTTP; not a measured microsecond budget), and a
separate process sends the files ([`tracewick-drain`](drain.md) or
[`tracewick-agent`](https://github.com/tracewick/agent)). This is the recommended setup in
production. Put the spool on a **local** filesystem ([spool format](spool-format.md)).

A `PyroscopeSink` sends over the network from the PHP process itself. Use it from `tracewick-drain`,
CLI, staging, or long-running workers — **not** from PHP-FPM request handlers. Timeouts default to
200 ms connect / 1 s total (raise them only outside the request path) and the circuit breaker
bounds retries, but the worker still pays that time, and DNS for hostnames is outside the socket
deadline ([ADR 8](adr/0008-own-http-transport.md)). Default `tracewick-drain` resolves before
claiming spool files and caches the IP briefly; see [drain.md](drain.md). Production FPM should
use `SpoolSink` only.

The circuit breaker classifies outcomes: transient failures (timeouts, 5xx) open after a few in a
row (default 3) for 30 seconds; configuration errors (401/403/404/413) open at once for 5 minutes;
a rejected payload leaves the breaker alone. State is **in-memory by default** (correct for a
single `tracewick-drain` and for any path that does not share HTTP delivery across processes). Set
`CircuitBreakerConfig::$stateDirectory` to opt into a shared file (mode `0700`) when several
delivery processes on one host must share one trial; if that file cannot be written, the process
falls back to in-memory state and logs a warning. FPM with `SpoolSink` does not use a breaker.

### PHP-FPM

```php
$profiler->start(['route' => $route]);
$profiler->registerShutdown();
```

At shutdown the profiler stops and flushes. Symfony and Laravel end the response to the client
with `fastcgi_finish_request()` when they send it, so the client never waits for the flush.

A plain PHP application that does not call `fastcgi_finish_request()` keeps the client waiting
until shutdown functions finish. With a `SpoolSink` that wait is negligible. Otherwise, set
`finishRequest: true` in `ProfilerConfig`: the profiler then ends the response itself before it
flushes. Output that shutdown functions registered after the profiler print is then lost, which is
why the option is off by default. See [ADR 10](adr/0010-deferred-delivery.md).

### `auto_prepend_file` (before the framework)

To include cold-start and autoloader time in the profile, point PHP at
[`examples/auto_prepend.php`](../examples/auto_prepend.php) (`auto_prepend_file` in `php.ini` or
`-d`). That script boots a `SpoolSink` profiler, starts one unit of work, registers shutdown
delivery, and stores the instance in `ProcessProfiler`.

Required environment: `TRACEWICK_APPLICATION`, `TRACEWICK_SPOOL_DIR`. Optional:
`TRACEWICK_SAMPLE_RATE` (0..1), `TRACEWICK_LABEL_RAW_PATH=1` (put the request path on `route`;
**off by default** — default `route` is `unmatched` until the app sets a template name).

After the router runs:

```php
use Tracewick\Runtime\ProcessProfiler;

ProcessProfiler::get()?->label('route', $routeName);
```

Label policy drops keys such as `url` / `user_id` / `email` / `trace_id` and values that look like
emails, UUIDs, query strings, or long digit runs — before they become series labels.

Use either prepend **or** a framework subscriber that creates its own profiler — not both for the
same request, or you double-sample. Prefer the framework integration when one exists; prepend is
for bare PHP-FPM and for early-start + late-label hybrids.

### Sequential long-running workers

In RoadRunner, FrankenPHP worker mode or a queue consumer that handles **one request at a time**,
construct **`UnitOfWorkProfiler` (and its sink) once per worker process**, then `start` / `stop` /
`flush` around each unit of work. Do **not** call `UnitOfWorkProfiler::create()` on every request:
tearing down and recreating Excimer-backed profilers in a permanent worker can grow RSS without
bound (seen on the RoadRunner benchmarks stand). PHP-FPM may create per request because the
process is recycled.

Always stop in a `finally` block so an exception from the handler cannot leave a profile running:

```php
$profiler = UnitOfWorkProfiler::create($sink, $config);
$profiler->registerShutdown();

while ($request = $worker->waitRequest()) {
    $profiler->start(['route' => routeOf($request)]);
    try {
        $worker->respond(handle($request));
    } finally {
        $profiler->stop();
        $profiler->flush();
    }
}
```

`registerShutdown()` once flushes anything still buffered when the worker exits. A forgotten
`stop()` is also healed by the next `start()`, which rotates the running profile into the buffer
first.

For **concurrent** request handling in one process (Swoole, Fibers), use
[`ContinuousProfiler`](#continuous-profiler) instead: per-request `start`/`stop` would mix stacks
across requests.

### Console commands

```php
$profiler = UnitOfWorkProfiler::create(
    $sink,
    new ProfilerConfig(maxProfileDurationSeconds: 15),
);
$profiler->start(['command' => 'app:import']);
$profiler->registerShutdown();
```

With `maxProfileDurationSeconds` greater than 0, a long command is split into successive profile
windows. The Excimer flush callback calls `pulse()` as samples arrive; a worker loop can also call
`pulse()` itself. Without a duration limit the profile covers the whole command until shutdown.

Sampling itself is capped in the sampler: Excimer drains its log every `maxSamples` hits into an
in-memory stack→count map, and stacks beyond `maxUniqueStacks` go into an `[overflow]` frame so the
profile stays bounded.

## Continuous profiler

`Tracewick\Runtime\ContinuousProfiler` is for process-level profiling when many units of work run
concurrently in one PHP process. Excimer samples the currently executing stack; a per-request
`start`/`stop` pair cannot attribute samples to the right request under Swoole or Fibers. This
adapter therefore:

- starts the sampler **once** with **process-level** labels from config;
- never takes per-request labels;
- lets Excimer's native flush aggregate stacks in the sampler and only marks `hasPendingSegment()`;
- on `pulse()` / `flush()` from the app loop, captures into an in-process `ProfileAccumulator`
  with the same aggregation key and 10–15 s window as drain
  ([ADR 12](adr/0012-aggregate-before-delivery.md)), then sends closed buckets to the sink
  (one merged profile per closed bucket; multi-POST across windows is normal).

```php
use Tracewick\Profile\Labels;
use Tracewick\Runtime\ContinuousProfiler;
use Tracewick\Runtime\ContinuousProfilerConfig;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

$profiler = ContinuousProfiler::create(
    new SpoolSink('blog', new SpoolConfig('/var/spool/tracewick')),
    new ContinuousProfilerConfig('blog', new Labels(['runtime' => 'swoole'])),
);
$profiler->start();
$profiler->registerShutdown();
// Required for delivery: call pulse() from the worker loop / timer. Excimer's flush callback only
// marks hasPendingSegment(); it never sends.
```

| Call | What it does |
| --- | --- |
| `start()` | Starts continuous sampling; a second call is a no-op |
| `pulse()` | Captures into the accumulator and sends closed windows (call from the app loop) |
| `flush()` | Same capture, then sends closed windows only |
| `hasPendingSegment()` | True after an Excimer segment until the next `pulse()` / `flush()` |
| `stop()` | Stops sampling and sends every remaining bucket |
| `registerShutdown()` | Stops and flushes on PHP shutdown |

## Diagnostics

`diagnostics()` counts what the profiler has done: profiles collected, dropped from a full buffer
(or full accumulator), delivered, rejected by the backend, failed, skipped (an open circuit breaker
or a full spool), **empty** (no samples — not the same as skipped), errors swallowed, whether the
sampler is a no-op and why (`inactiveSampler`), and how many failures the sampler itself swallowed
(`samplerFailures`). Under PHP-FPM a unit-of-work profiler lives for one request, so the numbers
cover that request; in a long-running worker they add up.

Optional host-wide counters (`Tracewick\Diagnostics\CounterStore`) survive FPM requests when APCu
is available, otherwise a small file under the system temp dir (`CounterStoreFactory::create()`).
Pass a store into the profiler constructor to mirror drop/skip/empty/delivery counts across workers.
Every counter name is product-prefixed (`tracewick:profiles_*`, `tracewick:agg:*`) so a shared APCu
userland or a shared counters directory does not collide with another library. The APCu iterator only
returns keys under that prefix; the file store writes `tracewick-counters.json`.

**Cardinality:** alpha default is on **`tracewick-drain`** (`CardinalityGate` + in-process store,
max 256). `SpoolSink` / `ContinuousProfiler` do **not** collapse label sets unless you inject a
gate with a real `CounterStore`. On overflow, `service` / `env` are kept and excess keys become
`cardinality=other`. Label values that look like email, UUID, or long digit runs log a warning
once per process per key; they are not stripped.

Give a PSR-3 logger to see problems as they happen:

```php
$sink = new PyroscopeSink($pyroscopeConfig, logger: $logger);
$profiler = UnitOfWorkProfiler::create($sink, $config, logger: $logger);
```

| Level   | Message                                                                     |
|---------|-----------------------------------------------------------------------------|
| warning | Profiling is inactive (disabled, missing ext-excimer, or init failure); once per process |
| warning | A profile could not be delivered, or the backend rejected it                |
| warning | The circuit breaker opened; logged once per host, by the process that opened it |
| warning | An error was swallowed; the exception is in the context                     |
| notice  | A label was dropped by the label policy (denied key/value); once per process per key |
| notice  | A profile was dropped from a full buffer, or the sink skipped it             |
| info    | The circuit breaker closed again                                            |

Tracewick never logs above warning: a profiler that cannot deliver is not an error of the
application. Filter by level or by channel in the logger.

Each message is written at most once a minute per process, and the next copy carries the number of
copies suppressed in between as `suppressed`. The interval lives in process memory, so suppress
does not touch the disk. Separate PHP-FPM workers each keep their own limits (an approximate
host-wide rate).
