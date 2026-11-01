# Spool format, version 1

> **Note:** This document specifies **v1** (pprof payload). High-load production will move to
> **v2** (compact stack→weight payload; pprof encoded in drain/agent) per
> [ADR 13](adr/0013-spool-v2-compact-payload.md). v1 remains readable during dual-read; it is not
> the final high-load contract. Aggregation before upload is [ADR 12](adr/0012-aggregate-before-delivery.md).

The spool is how Tracewick hands profiles to a separate delivery process, such as
[`tracewick-drain`](drain.md), a future `tracewick-agent`, Grafana Alloy or a sidecar of your own
([ADR 7](adr/0007-language-neutral-spool-format.md)).
The application writes each profile to a directory and continues; the delivery process reads the
directory, sends the profiles to a backend and removes them. Write cost is small compared with
encoding and HTTP, but it is not a measured microsecond budget (directory checks and disk I/O
scale with backlog).

This document is a public contract, independent of the PHP API. A reader or writer in any language
can be built from it and checked against the fixture files in
[`tests/Fixtures/spool/v1`](../tests/Fixtures/spool/v1).

The key words MUST, MUST NOT, SHOULD and MAY are used as described in RFC 2119.

## Directory

- The spool directory MUST be on a **local** filesystem for the writer and its readers (same host
  or the same Kubernetes volume as an `emptyDir`). MUST NOT place the spool on NFS or similar
  network filesystems: claim `rename` and durability assumptions do not hold there
  ([ADR 9](adr/0009-agent-hand-off.md)).
- A spool is one flat directory. Writers MAY create it; readers MUST NOT require any other file in
  it.
- A **spool file** is a regular file whose name ends in `.spool` and does not start with a dot.
  Readers MUST ignore every other entry.
- Writers name files `<time>-<random>.spool`, where `<time>` is the Unix time of writing in
  nanoseconds as 20 decimal digits padded with zeros, and `<random>` is 16 lowercase hexadecimal
  digits. Names therefore sort by writing time. Readers MUST NOT rely on the name for anything else
  than ordering, and SHOULD deliver older files first.
- Writers write a file under a temporary name that starts with a dot and ends in `.tmp`, in the
  same directory, and `rename()` it to its final name once it is complete. Readers therefore never
  see a partial spool file. A temporary file left behind by a crashed writer MAY be deleted by a
  reader when it is older than one hour.

## Limits

A writer is configured with three limits and checks them before every write:

| Limit             | Meaning                                                        | PHP default |
|-------------------|----------------------------------------------------------------|-------------|
| `max_files`       | Spool files in the directory, temporary files not counted      | 1000        |
| `max_file_bytes`  | Size of one spool file                                         | 4 MiB       |
| `min_free_bytes`  | Free space that must remain on the file system after the write | 64 MiB      |

The directory never holds more than `max_files × max_file_bytes` bytes of spool files. A writer
that would exceed a limit drops the profile and counts the drop. It never blocks, never waits for
a reader and never deletes files.

## File layout

All integers are unsigned and big-endian.

| Offset | Size     | Field          | Value                                             |
|--------|----------|----------------|---------------------------------------------------|
| 0      | 4        | magic          | ASCII `TWSP` (`54 57 53 50`)                      |
| 4      | 1        | version        | `1`                                               |
| 5      | 4        | header length  | `H`, the size of the header in bytes, at most 1 MiB |
| 9      | `H`      | header         | UTF-8 JSON object, described below                |
| 9 + H  | the rest | payload        | the profile, in the format the header names       |

### Header

| Key            | JSON type                 | Meaning                                                              |
|----------------|---------------------------|----------------------------------------------------------------------|
| `application`  | string                    | Application name; becomes `service_name` in Pyroscope                |
| `event_type`   | string                    | `wall` (wall-clock time) or `cpu` (CPU time)                          |
| `period_ns`    | integer                   | Sampling period in nanoseconds, at least 1                           |
| `start_ns`     | integer                   | Unix time in nanoseconds at which the profile window opened          |
| `end_ns`       | integer                   | Unix time in nanoseconds at which the window closed, ≥ `start_ns`    |
| `labels`       | object of string → string | Labels of the whole profile; may be empty                            |
| `format`       | string                    | Format of the payload, see below                                     |
| `payload_size` | integer                   | Size of the payload in bytes                                         |

All keys are required. Integers fit in a signed 64-bit integer. Readers MUST ignore keys they do
not know, so that version 1 can gain optional keys without a new version.

Labels are the labels of the profile as the application set them. Keys are letters, digits,
underscores and dots and do not start with a digit or a dot. Backend rules, such as Pyroscope
dropping keys that contain dots, are applied by the reader that delivers the profile, not by the
writer.

### Payload formats

| `format` | Payload                                                                          |
|----------|----------------------------------------------------------------------------------|
| `pprof`  | Uncompressed [profile.proto](https://github.com/google/pprof/blob/main/proto/profile.proto). Every sample carries exactly the profile labels as string labels and no others, so a reader can decide from the header alone whether label keys need renaming. |

Readers MUST NOT deliver a payload whose format they do not support.

## Reading

Any number of readers may read one spool directory at the same time, for example an agent that
restarts while its previous process is still running, or a drain started by cron while the
previous run is busy. Readers coordinate through the file system only
([ADR 9](adr/0009-agent-hand-off.md)).

### Claiming a file

1. A reader lists the directory and takes spool files in name order, oldest first.
2. It claims a file by renaming `X.spool` to `.X.spool.claimed-<reader id>` in the same directory,
   where the reader id is 1 to 64 letters, digits, dots, underscores or dashes and is unique among
   the readers of the directory (for example host name and process id). `rename()` within one
   directory is atomic: exactly one reader succeeds, and the others find the file gone and move
   on to the next one.
3. Right after claiming, the reader sets the modification time of the claimed file to the current
   time, so that other readers can tell an active claim from an abandoned one.
4. It reads the claimed file and validates it:
   1. the magic, the version and a header length of at most 1 MiB;
   2. the header keys and their types, as described above;
   3. that the rest of the file is exactly `payload_size` bytes;
   4. that it supports the `format`.
5. It delivers the payload and finishes the claim in one of three ways:
   - **acknowledge**: the payload was delivered; the reader deletes the claimed file.
   - **release**: delivery failed for a reason that may pass, such as a backend that is down; the
     reader renames the file back to `X.spool` and tries again later.
   - **reject**: the file failed validation or the backend refused it, which will not get better
     with time; the reader moves it to the subdirectory `rejected/` under its original name and
     counts it. It MAY delete the oldest files there to keep their number bounded.

### Abandoned files

- A claimed file whose modification time is older than the stale-claim age belongs to a reader that
  died. Any reader renames it back to `X.spool`. The age is configurable and defaults to 10
  minutes; it must be longer than a reader takes to deliver one file.
- A temporary file (`.<name>.tmp`) older than one hour belongs to a writer that crashed. Any reader
  MAY delete it.

Claimed and temporary files start with a dot, so writers do not count them towards `max_files`.

## Versioning

A change that an existing reader would misread gets a new version number. Readers support the
previous version side by side with the new one for at least one minor release of Tracewick.

## Fixtures

[`tests/Fixtures/spool/v1`](../tests/Fixtures/spool/v1) holds spool files that every reader and
writer is tested against. Its README lists what each file contains and the result a reader must
produce.
