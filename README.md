# Tracewick

[![CI](https://github.com/tracewick/tracewick/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/tracewick/tracewick/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/tracewick/tracewick/branch/main/graph/badge.svg)](https://codecov.io/gh/tracewick/tracewick)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

Tracewick is a sampling profiler for PHP, built on [ext-excimer](https://www.mediawiki.org/wiki/Excimer),
meant to run continuously in production.

> [!WARNING]
> Tracewick is in early development. There is no usable release yet, and the API will change.

## Goals

- **Safe in production.** Failures are caught and counted; the library must not throw into the host
  application. Overhead limits and delivery outside the request path are still being tightened
  (see [ADR 5](docs/adr/0005-fail-open-policy.md)).
- **Correct profiles.** Wall-time and CPU sampling with sample weights and timestamps that match
  Excimer where we have tests for it.
- **Open formats.** Collapsed stacks, Speedscope and pprof. Delivery today targets Grafana
  Pyroscope (`/ingest`). The intended production path is pprof over Pyroscope's push API; OpenTelemetry
  Profiles export is planned and experimental ([ADR 6](docs/adr/0006-otlp-profiles-experimental.md)).
- **Where PHP runs.** Designed for PHP-FPM, CLI and long-running workers. The public facade today is
  request- or unit-of-work oriented. Continuous process-level profiling (for example under Swoole or
  Fibers) is planned as a separate adapter, without per-request labels.
  Symfony and Laravel integrations are planned as separate packages; they are not in this repository.

## Requirements

- PHP 8.2 or newer
- [ext-excimer](https://pecl.php.net/package/excimer) to collect profiles

## Installation

```bash
composer require tracewick/tracewick
```

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues privately as described in
[SECURITY.md](SECURITY.md).

## License

Tracewick is licensed under the [Apache License 2.0](LICENSE). See [NOTICE](NOTICE) for attribution.
