# Tracewick

[![CI](https://github.com/tracewick/tracewick/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/tracewick/tracewick/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/tracewick/tracewick/branch/main/graph/badge.svg)](https://codecov.io/gh/tracewick/tracewick)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

Tracewick is a sampling profiler for PHP, built on [ext-excimer](https://www.mediawiki.org/wiki/Excimer),
meant to run continuously in production.

> [!WARNING]
> Tracewick **0.1.x is alpha**. The public API is not stable until 1.0.0 — expect breaking
> changes in minor releases. Pin a version and read the changelog before upgrading.

## Goals

- **Safe in production.** Failures are caught and counted; the library must not throw into the host
  application. Overhead limits and delivery outside the request path are still being tightened
  (see [ADR 5](docs/adr/0005-fail-open-policy.md)).
- **Correct profiles.** Wall-time and CPU sampling with sample weights and timestamps that match
  Excimer where we have tests for it.
- **Open formats.** Collapsed stacks, Speedscope and pprof. Production delivery uses pprof over
  Grafana Pyroscope's push API ([ADR 4](docs/adr/0004-pprof-as-primary-format.md)). OpenTelemetry
  Profiles export is planned and experimental ([ADR 6](docs/adr/0006-otlp-profiles-experimental.md)).
- **Where PHP runs.** PHP-FPM, CLI and sequential workers use `UnitOfWorkProfiler` (one profile per
  request or job). Concurrent runtimes such as Swoole, Fibers or ReactPHP use `ContinuousProfiler`:
  process-level sampling without per-request labels. Symfony and Laravel integrations are planned
  as separate packages; they are not in this repository.

## Requirements

- PHP 8.2 or newer
- [ext-excimer](https://www.mediawiki.org/wiki/Excimer) to collect profiles (optional at install time)

**Fail-open.** Public profiler/sink calls catch failures and never throw into the host application.
Without ext-excimer the library is a **no-op** (warn once, then idle). Turn profiling off without a
deploy via the kill switch: `TRACEWICK_DISABLE_FILE` → `TRACEWICK_ENABLED` / `tracewick.enabled`
([docs/runtime.md](docs/runtime.md), [ADR 5](docs/adr/0005-fail-open-policy.md)).

## Installation

```bash
composer require tracewick/tracewick
```

Install the extension separately. Prefer [PIE](https://php.github.io/pie/) (PECL is deprecated):

```bash
pie install wikimedia/excimer
php -m | grep -i excimer
```

Debian/Ubuntu, Alpine, PECL fallback, and a local Alpine proof Dockerfile
(`docker/php/Dockerfile.alpine`, not a Docker Hub image) are in
[docs/install-excimer.md](docs/install-excimer.md).

For PHP-FPM without a framework package, [`examples/auto_prepend.php`](examples/auto_prepend.php)
can start profiling before the app boots (`auto_prepend_file`); see [docs/runtime.md](docs/runtime.md).

## Quick start

Recommended path: the app only writes a local spool; a separate process pushes to Pyroscope.

```php
use Tracewick\Profile\Labels;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sink\SpoolSink;
use Tracewick\Transport\SpoolConfig;

$profiler = UnitOfWorkProfiler::create(
    new SpoolSink('blog', new SpoolConfig('/var/spool/tracewick')),
    new ProfilerConfig(new Labels(['env' => 'prod'])), // default sampleRate 0.1
);

$profiler->start(['route' => $route]);
$profiler->registerShutdown(); // stop + flush to the spool when the request ends
```

In another process (cron, supervisor, or long-running sidecar), deliver the spool to Pyroscope:

- **Recommended for a long-running sidecar:** [`tracewick/agent`](https://github.com/tracewick/agent)
  (`tracewick-agent`) — Go process with HTTP connection reuse.
- **Ships with this library:** `vendor/bin/tracewick-drain` — PHP drain, fine for cron / `--once`
  and as a reference implementation.

```bash
# Go agent (separate repo)
tracewick-agent run --spool-dir=/var/spool/tracewick --pyroscope-url=http://127.0.0.1:4040

# or PHP drain
vendor/bin/tracewick-drain \
  --spool-dir=/var/spool/tracewick \
  --pyroscope-url=http://127.0.0.1:4040
```

Put the spool on a **local** volume or tmpfs with an OS quota if you need a hard ceiling: file
admission is **soft** and may briefly overshoot under concurrent writers (Tracewick does not run a
host-wide file counter on every FPM write). Both drain and agent apply a **process-local**
cardinality gate (default 256 keys; keeps `service`/`env` on overflow). Writer-side cardinality is
off unless you inject a store. Details: [spool format](docs/spool-format.md),
[ADR 12](docs/adr/0012-aggregate-before-delivery.md), [docs/drain.md](docs/drain.md),
[agent README](https://github.com/tracewick/agent#readme). Do not call `PyroscopeSink` from PHP-FPM
request handlers. Full PHP options: [docs/runtime.md](docs/runtime.md).

### See it on the stand

```bash
make stand-up   # Pyroscope, Grafana, OTel Collector, Toxiproxy
make app-up     # Symfony Demo on FPM (:8081) and RoadRunner (:8082) + drain
```

Hit the demo, then open Grafana at http://127.0.0.1:3000 (Explore / Drilldown → Profiles;
service `symfony-demo`). Details: [CONTRIBUTING.md](CONTRIBUTING.md).

### Capacity evidence

Load / soak / capacity harness lives in the sibling repo
[tracewick/benchmarks](https://github.com/tracewick/benchmarks) — not in this library. Clone →
`make up` → `make bench-smoke` (or `bench-overhead` / `bench-spool-pressure` / `bench-chaos` /
`bench-rps`).

Canonical cloud goldens use depth-48 synthetic stacks on an 8 vCPU Timeweb VPS (Packagist
`0.1.0`). At 20 RPS / `sampleRate` 0.1 vs off: about **+0.5–1.7 ms** latency and **~+1 pp**
PHP-FPM CPU. Spool soft admission and Pyroscope latency chaos held the app at **0%** HTTP
failures (profiles ~5× larger in bytes than shallow stacks). A single-instance high-RPS hold
with the same work shape sustained **~700 RPS** cleanly. Full tables and pins:
[overhead](https://github.com/tracewick/benchmarks/blob/main/reports/2026-11-06-overhead-depth48-cloud.md),
[spool pressure](https://github.com/tracewick/benchmarks/blob/main/reports/2026-11-06-spool-pressure-depth48-cloud.md),
[chaos](https://github.com/tracewick/benchmarks/blob/main/reports/2026-11-06-chaos-pyroscope-depth48-cloud.md),
[high-RPS pipeline](https://github.com/tracewick/benchmarks/blob/main/reports/2026-11-06-rps-pipeline-cloud.md).

Wall profile from that stand (`service_name=benchmarks-workload`, `route=work`,
`WORK_STACK_DEPTH=48` — deep `stackLayer*` frames into `busyWork`):

![benchmarks-workload wall flamegraph (depth-48)](docs/images/capacity/pyroscope-benchmarks-workload-wall.png)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues privately as described in
[SECURITY.md](SECURITY.md).

## License

Tracewick is licensed under the [Apache License 2.0](LICENSE). See [NOTICE](NOTICE) for attribution.
