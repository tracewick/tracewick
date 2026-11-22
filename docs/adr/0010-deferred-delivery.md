# 10. Deferred delivery in the Runtime

- Status: Accepted
- Date: 2026-10-29
- Updated: 2026-11-17

## Context

When does a finished profile reach the sink, how much may sit in memory, and how does PHP-FPM
avoid making the client wait for delivery?

In PHP-FPM the client gets the response when the script ends (including shutdown functions),
unless something calls `fastcgi_finish_request()`. Symfony and Laravel often do; plain PHP often
does not. Output after that call is discarded.

## Decision

- `stop()` builds the profile into a bounded buffer (default 16). Overflow → drop and count.
- `flush()` sends the buffer to the sink. FPM/CLI usually flush from `registerShutdown()`;
  workers flush after each unit of work.
- Recommended production sink: `SpoolSink` (local write; network in another process). Write cost
  is low relative to encode/HTTP; it is not a guaranteed microsecond SLA.
- `finishRequest` defaults to **false**. Set it to call `fastcgi_finish_request()` before the
  shutdown flush when the app wants that trade-off (later shutdown output is lost).

Why not always call `fastcgi_finish_request()`: it would silently drop later shutdown output
(toolbar, logger) and is hard to debug. Opt-in keeps the default predictable.

## Automatic UoW rotation and diagnostics

Automatic duration rotation remains enabled from the Excimer segment listener. It buffers
completed profiles in memory; it does not deliver them. Runtime counter-store and logger calls
from this path are deferred to an explicit `stop()`, `pulse()` or `flush()` outside the callback
(including shutdown). `diagnostics()` remains an immediate, in-memory view.

Deferred state consists of cumulative totals for a fixed set of counter keys and the latest
rotation error, rather than a queue of messages. Overflow and error logs are summarized at the
next publication. Publication snapshots are acknowledged before calling external code; callbacks
arriving during publication remain pending for the next call. A failed external counter update
is not retried, since it may already have applied its increment; the failure remains observable
in local diagnostics. External diagnostics may therefore lag, and are best-effort.

## Consequences

- Work path does not send; buffer size is capped (sample memory inside Excimer is a separate gap,
  [ADR 5](0005-fail-open-policy.md)).
- Network sink without `finishRequest` → client may wait for delivery; prefer `SpoolSink`.
- Kill -9 loses the small buffer.
