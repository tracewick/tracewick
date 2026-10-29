# AGENTS.md

Instructions for AI coding agents working on this repository. Humans should read
[CONTRIBUTING.md](CONTRIBUTING.md); everything there applies to agents too.

## Project

Tracewick (`tracewick/tracewick`, namespace `Tracewick\`) is a sampling profiler for PHP built on
ext-excimer, meant to run continuously in high-load production. It is a clean-room implementation:
do not copy code from Excimetry or any other project.

## Commands

```bash
composer install
make ci                 # everything CI runs on a pull request; must pass before you push
make cs-fix             # fix coding style and normalize composer.json
make stan               # PHPStan, level max
make test-unit          # fast unit tests
make test-integration   # needs ext-excimer; skipped without it
```

## Layout

- `src/` library code, PSR-4 `Tracewick\`.
- `tests/Unit/` tests without ext-excimer or I/O; `tests/Integration/` tests against the real
  extension. PSR-4 `Tracewick\Tests\`.
- `docs/adr/` architecture decision records. Read the accepted ADRs before changing architecture.

## Architecture

Layers depend only downward; a lower layer never imports a higher one:

1. **Sampler**: wraps `ExcimerProfiler` behind an interface.
2. **Profile**: immutable model of aggregated, weighted stacks with timestamps and labels.
3. **Encoder**: collapsed, Speedscope, pprof. Every encoder has golden-file tests.
4. **Transport**: delivers bytes (file, HTTP via PSR-18, spool directory, local agent socket).
5. **Sink**: combines an encoder and a transport with backend-specific rules.
6. **Runtime**: lifecycle in FPM, CLI and long-running workers.

This structure is the target; most layers do not exist yet.

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
- Commit messages and PR titles follow Conventional Commits.
- Work through pull requests; never push to `main`.
