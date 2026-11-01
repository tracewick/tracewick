# Spool format fixtures, version 2

Valid spool v2 files written by `SpoolSink` (compact-stacks payload). Regenerate with
`TRACEWICK_UPDATE_GOLDEN=1 composer test:unit` and review the diff.

| File                | Contents                                                                 |
|---------------------|--------------------------------------------------------------------------|
| `web-request.spool` | Same profile as v1 `web-request.spool`, format `compact-stacks`          |
| `edge-cases.spool`  | Same profile as v1 `edge-cases.spool`, format `compact-stacks`           |

v1 fixtures remain under [`../v1`](../v1) for dual-read coverage.
