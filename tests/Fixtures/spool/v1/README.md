# Spool format fixtures, version 1

Files in the [spool format](../../../../docs/spool-format.md) that every spool reader and writer is
tested against, including readers in other languages such as `tracewick-agent`. The valid files
are written by the PHP library (`SpoolSink`); regenerate all of them with
`TRACEWICK_UPDATE_GOLDEN=1 composer test:unit` and review the diff.

The payloads are the pprof golden files of the PHP encoder tests,
[`pprof-web-request.pb`](../../../Unit/Encoder/golden/pprof-web-request.pb) and
[`pprof-edge-cases.pb`](../../../Unit/Encoder/golden/pprof-edge-cases.pb).

## Valid

A reader must accept these files.

| File                      | Contents                                                                                              |
|---------------------------|-------------------------------------------------------------------------------------------------------|
| `web-request.spool`       | `blog`, wall time, period 10 ms, window 1798000000000000000 + 200 ms, label `service=blog`, pprof     |
| `edge-cases.spool`        | `blog`, CPU time, period 10 ms, window 1798000000000000000 + 100 ms, no labels (`{}`), pprof          |
| `unknown-header-key.spool`| `web-request.spool` with an extra header key `x_future` holding nested JSON; the key must be ignored  |

## Valid layout, unsupported content

| File                   | Expected result                                                                             |
|------------------------|---------------------------------------------------------------------------------------------|
| `unknown-format.spool` | Parses, but `format` is `x-unknown`: a reader must not deliver it and moves it aside        |

## Invalid

A reader must reject these files and must not deliver them. Each is `web-request.spool` with one
defect.

| File                        | Defect                                                         |
|-----------------------------|----------------------------------------------------------------|
| `bad-magic.spool`           | Starts with `XWSP` instead of `TWSP`                           |
| `unsupported-version.spool` | Version byte is 3                                              |
| `header-too-large.spool`    | Header length is 1 MiB + 1                                     |
| `truncated-payload.spool`   | Payload is one byte shorter than `payload_size`                |
| `header-not-json.spool`     | Header is cut off JSON                                         |
| `missing-key.spool`         | Header has no `start_ns`                                       |
| `end-before-start.spool`    | `end_ns` is before `start_ns`                                  |
