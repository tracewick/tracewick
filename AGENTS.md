# AGENTS.md

Instructions for AI coding agents working on this repository. Humans should read
[CONTRIBUTING.md](CONTRIBUTING.md); everything there applies to agents too.

## Project

Tracewick (`tracewick/tracewick`, namespace `Tracewick\`) is a sampling profiler for PHP built on
ext-excimer. Do not copy code from Excimetry or any other project. Excimetry is prior art only
(see [NOTICE](NOTICE)).

## Commands

```bash
composer install
make ci                 # lint, static analysis, unit/integration tests, composer audit
make cs-fix             # fix coding style and normalize composer.json
make stan               # PHPStan, level max
make test-unit          # fast unit tests
make test-integration   # needs ext-excimer; skipped without it
make stand-up           # docker-compose stand: Pyroscope, Grafana, OTel Collector, Toxiproxy
make stand-ci           # `make ci` inside the stand's PHP container, with ext-excimer
make app-up             # reference app: Symfony Demo on PHP-FPM (:8081) and RoadRunner (:8082)
```

`make ci` is required before you push, but it is **not** the full GitHub Actions matrix. CI also
runs Codecov (coverage gate), Infection (MSI), and `go tool pprof` validation of pprof goldens.
Those live in `.github/workflows/`; run them locally when you change encoders, coverage-sensitive
code, or CI config.

## Layout

- `src/` library code, PSR-4 `Tracewick\`.
- `tests/Unit/` tests without ext-excimer or I/O; `tests/Integration/` tests against the real
  extension. PSR-4 `Tracewick\Tests\`.
- `compose.yaml`, `docker/` the development stand (see CONTRIBUTING.md).
- `docs/adr/` architecture decision records. Read the accepted ADRs before changing architecture.

## Architecture

Layers depend only downward; a lower layer never imports a higher one:

1. **Sampler**: wraps `ExcimerProfiler` behind an interface.
2. **Profile**: immutable model of aggregated, weighted stacks with timestamps and labels.
3. **Encoder**: collapsed, Speedscope, pprof. Every encoder has golden-file tests.
4. **Transport**: delivers bytes. Implemented today: own HTTP client ([ADR 8](docs/adr/0008-own-http-transport.md))
   and a spool directory. A unix-socket hand-off is proposed, not implemented
   ([ADR 9](docs/adr/0009-agent-hand-off.md)).
5. **Sink**: combines an encoder and a transport with backend-specific rules (`PyroscopeSink`,
   `SpoolSink`).
6. **Runtime**: `Profiler`, spool drain, CLI. Framework packages and a continuous worker adapter
   are not in this repository yet.

Cross-cutting helpers (`Clock`, `Diagnostics`, `Exception`) sit beside these layers.

## Rules

- **Fail-open.** Code that runs inside the host application must never throw into it, block it, or
  add unbounded overhead. Catch, count and report failures through diagnostics instead.
- Every PHP file declares `strict_types=1`. Classes are `final` unless designed for extension.
  Library exceptions implement `Tracewick\Exception\TracewickException`.
- PHPStan runs at level `max`. Do not add baseline entries or ignores without a justifying comment.
- Never skip, disable, or delete a test to make CI green. Fix the cause.
- Use typed value objects for configuration, not loosely-typed arrays.
- Do not add runtime dependencies without discussing them in an issue; keep `require` minimal.
- Everything in the repository is written in English: code, comments, commit messages, PR texts,
  and documentation.
- Commit messages and PR titles follow Conventional Commits. `CHANGELOG.md` is generated from them
  by release-please; never edit it in a feature branch.
- Follow git flow (see CONTRIBUTING.md): branch `feature/*` from `develop` and open pull requests
  into `develop`. Never push to `main` or `develop` directly.
  Write ADRs in plain language: Context, Decision, Consequences. Prefer short sentences over rhetoric.
