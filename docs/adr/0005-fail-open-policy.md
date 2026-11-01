# 5. Fail-open policy and optional ext-excimer

- Status: Accepted
- Date: 2026-10-29
- Updated: 2026-11-01

## Context

Tracewick runs inside production request paths. If it throws, blocks on a slow backend, or grows
memory without a bound, it becomes an outage. It must also install on hosts without ext-excimer
(laptops, CI, partial rollouts).

## Decision

- **Runtime paths must not throw into the host.** Public entry points used at run time catch
  `\Throwable`, count the failure, report through a rate-limited PSR-3 logger and diagnostics
  when available, and return.
- **Invalid configuration fails fast** when building value objects (boot / tests).
- **ext-excimer is optional** (`suggest`, not `require`). Without it the factory returns a no-op
  sampler.
- **Kill switch.** Sampling is disabled when, in order: the path in `TRACEWICK_DISABLE_FILE`
  exists (stat once per process); or `TRACEWICK_ENABLED` is a disabling synonym in `$_SERVER`,
  then `$_ENV`, then `getenv`; or `ini_get('tracewick.enabled')` is a disabling synonym. Unset
  means enabled.
- **Bounded cost.** Buffers, label sets, spool limits and HTTP timeouts must have explicit caps.
  When a limit is hit, drop and count — do not queue without bound.
- **Delivery.** Prefer spool + a separate process, or finish the response before network I/O
  ([ADR 10](0010-deferred-delivery.md)). Direct HTTP from the request is supported but not the
  recommended production path.

## Implementation gaps (track in code / fix waves)

These belong to the decision but are **not** fully true of the current code:

- `ProfilerConfig::finishRequest` defaults to `false`, so shutdown may still send on the request
  path unless the app opts in.
- DNS resolution for hostnames is outside the HTTP socket timeout ([ADR 8](0008-own-http-transport.md)).

## Consequences

- Failures need tests that they do not escape.
- Diagnostics are part of the product, not optional polish.
- Docs must say clearly that without ext-excimer nothing is collected.
