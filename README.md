# Tracewick

[![CI](https://github.com/tracewick/tracewick/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/tracewick/tracewick/actions/workflows/ci.yml)
[![Coverage](https://codecov.io/gh/tracewick/tracewick/branch/main/graph/badge.svg)](https://codecov.io/gh/tracewick/tracewick)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

Tracewick is a sampling profiler for PHP, built on [ext-excimer](https://www.mediawiki.org/wiki/Excimer),
and designed to run continuously in high-load production.

> [!WARNING]
> Tracewick is in early development. There is no usable release yet, and the API will change.

## Goals

- **Safe in production.** Low, bounded overhead; Tracewick never throws into, blocks, or slows down
  the host application (fail-open).
- **Correct profiles.** Wall-time and CPU sampling with accurate sample weights and timestamps.
- **Open formats.** Collapsed stacks, Speedscope and pprof, with export to Grafana Pyroscope and
  OpenTelemetry.
- **Works where PHP runs.** PHP-FPM, CLI, long-running workers (RoadRunner, FrankenPHP, Swoole),
  with Symfony and Laravel integrations.

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
