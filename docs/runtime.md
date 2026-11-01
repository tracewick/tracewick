# Profiling requests and jobs

`Tracewick\Runtime\Profiler` is the entry point of the library. It profiles one unit of work at a
time, such as an HTTP request, a console command or a queue job, and delivers the profiles after
the work is done, never while it runs ([ADR 5](adr/0005-fail-open-policy.md)).

```php
use Tracewick\Profile\Labels;
use Tracewick\Runtime\Profiler;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

$profiler = Profiler::create(
    new SpoolSink('blog', new SpoolConfig('/var/spool/tracewick')),
    new ProfilerConfig(new Labels(['env' => 'prod'])),
);
```

`Profiler::create()` picks the best sampler for the process. Without ext-excimer, or with
`TRACEWICK_ENABLED=0`, the profiler collects and sends nothing, and the application runs as usual.

## The lifecycle

| Call                     | What it does                                                                 |
|--------------------------|------------------------------------------------------------------------------|
| `start(['route' => …])`  | Starts sampling; the labels apply to this profile only                       |
| `label('route', …)`      | Sets a label of the running profile, for values known only later            |
| `stop()`                 | Stops sampling and keeps the profile in a buffer in memory                  |
| `flush()`                | Hands the buffered profiles to the sink                                      |
| `registerShutdown()`     | Makes PHP call `stop()` and `flush()` when it shuts down                     |

None of these methods throws. An invalid label is ignored, a failure costs only the profile it
concerns, and `lastFailure()` returns the last error swallowed. See [Diagnostics](#diagnostics).

The buffer holds `maxBufferedProfiles` profiles, 16 by default. A profile that does not fit is
dropped and counted; it is never sent from inside the work.

## Profiling a share of requests

Every profiled request pays for sampling and for building and storing its profile. On a busy
service a share of the requests gives the same flame graphs at a fraction of that cost:

```php
new ProfilerConfig(new Labels(['env' => 'prod']), sampleRate: 0.1); // one request in ten
```

`start()` decides at random for each unit of work. For the others it does nothing: the sampler
does not run, and `label()` and `stop()` have no effect. The default rate is 1, every unit of
work; 0 turns profiling off without removing the calls.

The profiles show only the requests that were profiled, so absolute totals in Pyroscope shrink
by the rate. Shares within a flame graph, which is what profiling is read for, stay the same.

## Which sink

Delivery happens in `flush()`, after the work, but in the same process. With a `SpoolSink` it is a
local file write (cheap next to encoding and HTTP; not a measured microsecond budget), and a
separate process sends the files ([`tracewick-drain`](drain.md); a Go agent is planned). This is
the recommended setup in production. Put the spool on a **local** filesystem
([spool format](spool-format.md)).

A `PyroscopeSink` sends over the network from the PHP process itself. Prefer it for staging and
debugging, not for high-load FPM: timeouts and the circuit breaker bound time spent, but the
worker still pays that time, and DNS for hostnames is outside the socket deadline
([ADR 8](adr/0008-own-http-transport.md)).

The circuit breaker stops sending after a few failed deliveries in a row (default 3) and tries
again after 30 seconds. State is a small file (default under the system temp dir; set
`stateDirectory` in `CircuitBreakerConfig`). Sharing across FPM workers is best-effort until the
store uses atomic updates; docs that promised a local in-memory fallback when the file cannot be
written describe the intended behaviour — check the current `FileCircuitBreakerStore` if you rely
on it.

## PHP-FPM

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

## Long-running workers

In RoadRunner, Swoole, FrankenPHP worker mode or a queue consumer, PHP does not shut down between
requests. Start and stop a profile around each unit of work and flush after it:

```php
while ($request = $worker->waitRequest()) {
    $profiler->start(['route' => routeOf($request)]);
    $worker->respond(handle($request));
    $profiler->stop();
    $profiler->flush();
}
```

Call `registerShutdown()` once as well, so that profiles still buffered when the worker exits are
not lost.

## Console commands

```php
$profiler->start(['command' => 'app:import']);
$profiler->registerShutdown();
```

The same as PHP-FPM: the profile covers the command and is flushed when the script ends.

## Diagnostics

`diagnostics()` counts what the profiler has done: profiles collected, dropped from a full buffer,
delivered, rejected by the backend, failed, skipped (an open circuit breaker or a full spool), and
errors swallowed. Under PHP-FPM a profiler lives for one request, so the numbers cover that
request; in a long-running worker they add up, and can be exported to the metrics of the
application. Host-wide metrics will come from `tracewick-agent`.

Give a PSR-3 logger to see problems as they happen:

```php
$sink = new PyroscopeSink($pyroscopeConfig, logger: $logger);
$profiler = Profiler::create($sink, $config, logger: $logger);
```

| Level   | Message                                                                     |
|---------|-----------------------------------------------------------------------------|
| warning | A profile could not be delivered, or the backend rejected it                |
| warning | The circuit breaker opened; logged once per host, by the process that opened it |
| warning | An error was swallowed; the exception is in the context                     |
| notice  | A profile was dropped from a full buffer, or the sink skipped it             |
| info    | The circuit breaker closed again                                            |

Tracewick never logs above warning: a profiler that cannot deliver is not an error of the
application. Filter by level or by channel in the logger.

Each message is written at most once a minute per host, and the next copy carries the number of
copies suppressed in between as `suppressed`. The minute is kept in a small file per message in
the system temporary directory, because PHP-FPM requests share no memory; the file is only touched
when there is something to log.

