# Spool format

The spool is how Tracewick hands profiles to a separate delivery process, such as
[`tracewick-drain`](drain.md), a future `tracewick-agent`, Grafana Alloy or a sidecar of your own
([ADR 7](adr/0007-language-neutral-spool-format.md)).
The application writes each profile to a directory and continues; the delivery process reads the
directory, sends the profiles to a backend and removes them.

**v2** (compact stack→weight payload; pprof encoded in drain/agent) is the production writer
format ([ADR 13](adr/0013-spool-v2-compact-payload.md)). **v1** (pprof payload) remains readable.
Aggregation before upload is [ADR 12](adr/0012-aggregate-before-delivery.md).

This document is a public contract, independent of the PHP API. Readers and writers are checked
against [`tests/Fixtures/spool/v1`](../tests/Fixtures/spool/v1) and
[`tests/Fixtures/spool/v2`](../tests/Fixtures/spool/v2).

The key words MUST, MUST NOT, SHOULD and MAY are used as described in RFC 2119.

## Directory

- The spool directory MUST be on a **local** filesystem for the writer and its readers (same host
  or the same Kubernetes volume as an `emptyDir`). MUST NOT place the spool on NFS or similar
  network filesystems: claim `rename` and durability assumptions do not hold there
  ([ADR 9](adr/0009-agent-hand-off.md)).
- Writers create the root and shard directories with mode `0700` and spool files with mode `0600`.
- A **spool file** is a regular file whose basename ends in `.spool` and does not start with a
  dot. Readers MUST ignore every other entry except when recovering claims and temporary files.
- **Shards:** when the writer knows an aggregation-key hash, it places the file under a
  subdirectory named with the first two hexadecimal digits of that hash (for example `ab/`).
  Readers MUST scan those two-hex directories. Files written without a hash MAY sit in the root.
- **Names:**
  - Without a hash: `<time>-<random>.spool`
  - With a hash: `<time>-<aggHash>-<random>.spool`
  where `<time>` is Unix time of writing in nanoseconds as 20 decimal digits, `<aggHash>` is 16
  lowercase hex digits (SHA-256 prefix of the aggregation key), and `<random>` is 16 lowercase
  hex digits. Names sort by writing time. Readers SHOULD deliver older names first. The hash is a
  hint for grouping ([ADR 12](adr/0012-aggregate-before-delivery.md)); readers MUST still accept
  files after parsing the header when the hash is absent or unknown.
- Writers write under a temporary name that starts with a dot and ends in `.tmp`, in the same
  directory as the final file, and `rename()` it into place. A temporary file left behind by a
  crashed writer MAY be deleted when older than one hour.

## Limits

A writer is configured with three limits and checks them before every write:

| Limit             | Meaning                                                        | PHP default |
|-------------------|----------------------------------------------------------------|-------------|
| `max_files`       | Spool files under the root (all shards), temps not counted     | 500         |
| `max_file_bytes`  | Size of one spool file                                         | 4 MiB       |
| `min_free_bytes`  | Free space that must remain on the file system after the write | 64 MiB      |

The file-count check MAY use a short-lived cache (about 1 s or every few dozen writes) and MAY
briefly overshoot `max_files` under concurrent writers (soft admission). A writer that would
exceed a limit drops the profile and counts the drop. It never blocks, never waits for a reader
and never deletes spool files. Readers MUST reject files larger than their configured size ceiling
without forwarding them.

## File layout

All integers are unsigned and big-endian.

| Offset | Size     | Field          | Value                                              |
|--------|----------|----------------|----------------------------------------------------|
| 0      | 4        | magic          | ASCII `TWSP` (`54 57 53 50`)                       |
| 4      | 1        | version        | `1` (pprof payload) or `2` (compact-stacks payload)|
| 5      | 4        | header length  | `H`, the size of the header in bytes, at most 1 MiB |
| 9      | `H`      | header         | UTF-8 JSON object, described below                 |
| 9 + H  | the rest | payload        | the profile, in the format the header names        |

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
not know.

### Payload formats

| `format`          | Version | Payload |
|-------------------|---------|---------|
| `pprof`           | 1       | Uncompressed [profile.proto](https://github.com/google/pprof/blob/main/proto/profile.proto). |
| `compact-stacks`  | 2       | Compact stack→weight encoding (schema below). Drain/agent encode pprof from it. |

Readers MUST NOT deliver a payload whose format they do not support.

#### `compact-stacks` schema version 1

- 1 byte schema version (`1`)
- u32 string count; each string is u32 length + UTF-8 bytes
- u32 stack count; each stack is u32 weight, u8 flags (bit 0 = truncated), u32 frame count,
  then for each frame: u32 function index, u32 class index, u32 file index, u32 line,
  u32 closure line (`0xFFFFFFFF` means a null optional string or null closure line)

## Reading

Any number of readers may read one spool directory at the same time. Readers coordinate through
the file system only ([ADR 9](adr/0009-agent-hand-off.md)).

### Claiming a file

1. A reader lists the root and two-hex shard directories and takes spool files in name order,
   oldest first.
2. It claims a file by renaming `X.spool` to `.X.spool.claimed-<reader id>-<epoch>` in the same
   directory, where `<epoch>` is the Unix time in seconds when the claim started, and the reader
   id is 1 to 64 letters, digits, dots, underscores or dashes. `rename()` within one directory is
   atomic: exactly one reader succeeds.
3. Abandoned claims are detected from `<epoch>` in the claimed name (not from mtime after rename).
   Legacy claims without an epoch MAY fall back to mtime for one transition period.
4. It reads the claimed file (after a size ceiling check) and validates magic, version, header and
   payload.
5. It finishes the claim:
   - **acknowledge**: delete the claimed file (count a failure if delete fails after a successful
     send);
   - **release**: rename back to `X.spool`;
   - **reject**: move to `rejected/` under the basename and optionally trim that directory.

### Abandoned files

- A claimed file whose claim epoch is older than the stale-claim age (default 10 minutes) is
  returned to `X.spool` by any reader.
- A temporary file (`.<name>.tmp`) older than one hour MAY be deleted.

## Versioning

Readers support the previous version side by side with the new one for at least one minor release
of Tracewick.

## Fixtures

- [`tests/Fixtures/spool/v1`](../tests/Fixtures/spool/v1) — version 1 / `pprof`
- [`tests/Fixtures/spool/v2`](../tests/Fixtures/spool/v2) — version 2 / `compact-stacks`
