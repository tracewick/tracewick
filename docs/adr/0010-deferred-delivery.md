# 10. Deferred delivery in the Runtime

- Status: Accepted
- Date: 2026-10-29
- Updated: 2026-11-01

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

## Consequences

- Work path does not send; buffer size is capped (sample memory inside Excimer is a separate gap,
  [ADR 5](0005-fail-open-policy.md)).
- Network sink without `finishRequest` → client may wait for delivery; prefer `SpoolSink`.
- Kill -9 loses the small buffer.
