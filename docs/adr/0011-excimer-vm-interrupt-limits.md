# 11. Excimer samples on VM interrupt, not wall clocks inside C calls

- Status: Accepted
- Date: 2026-11-01

## Context

ext-excimer attributes time using PHP's `vm_interrupt` flag. When a sample period elapses while
PHP is inside a long C call (PDO, curl, etc.), Excimer records the sample when that call returns.
Weight (`event_count`) can be greater than one; there is still a single timestamp for that sample.

## Decision

- Treat this as a limit of the data model, not a bug to paper over in Tracewick.
- Do not design features that need a fine-grained timeline *inside* one blocking C call (for
  example reconstructing sub-intervals from successive `timestampNs` alone).
- Aggregate weights remain valid for flame graphs and Pyroscope series; wall-time spent waiting in
  C still shows up on the PHP frame that called into C.

## Consequences

- Span-style "what happened during these 200 ms of curl" needs another signal (tracing), not only
  Excimer sample timestamps.
- Docs and future ADRs about span profiles must not assume per-sample wall clocks denser than
  Excimer provides.
