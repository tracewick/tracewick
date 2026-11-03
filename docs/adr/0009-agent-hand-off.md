# 9. Handing profiles to a local agent: spool readers (and a proposed unix socket)

- Status: Accepted (spool readers); Proposed (unix socket)
- Date: 2026-10-29
- Updated: 2026-11-03

## Context

[ADR 5](0005-fail-open-policy.md) keeps network delivery out of the hot path when possible.
[ADR 7](0007-language-neutral-spool-format.md) defines the spool directory for a separate delivery
process (`tracewick-drain`, a future `tracewick-agent`, Alloy, or a custom sidecar). Open questions:

- Several readers on one directory (restarts, cron overlap, optional parallelism).
- Whether users can plug other stores without forking the library.
- Whether a local unix socket is needed when there is no writable disk.

UDP was rejected: profiles are too large for a datagram, and drops are silent.

## Decision

### Spool readers (Accepted — implemented)

- The spool directory is the primary hand-off. Readers coordinate with the file system only.
- Claim: rename `X.spool` → `.X.spool.claimed-<reader id>-<epoch>` in the same directory, where
  `<epoch>` is Unix time in seconds when the claim started and `<reader id>` is 1–64 of
  `[A-Za-z0-9._-]`. One winner; others see "not found". Abandoned claims are detected from
  `<epoch>` in the name (not from mtime after rename). Legacy names without an epoch MAY fall back
  to mtime for one transition period. Canonical detail:
  [`docs/spool-format.md`](../spool-format.md).
- On success, delete (acknowledge). On retryable failure, rename back (release). Invalid files go
  to `rejected/` as in the spool format doc.
- Claimed files whose epoch is older than a configured age (default 10 minutes) are returned to
  the queue by any reader. **Readers must finish or release a claim before that age** (with margin
  for clock skew). Holding a claim longer than the stale age allows another reader to reclaim the
  file while the first still processes it — delivery is **at-least-once**, not exactly-once.
- Writers ignore dotfiles; claimed files do not count toward soft `max_files`.
- **Filesystem:** the spool directory and reader claim protocol assume a **local** filesystem.
  Do not put the spool on NFS or other network volumes: `rename` semantics and durability differ.
  Prefer a dedicated volume or tmpfs on the same host as the writer.
- **Permissions:** writers create the directory with mode `0700` and spool files with `0600`.
  Sidecar readers must share a compatible UID/GID (or an explicit ACL policy); a shared volume
  alone is not enough.
- `flock()` is not part of the claim protocol. Circuit-breaker / diagnostic state files may use
  locking on a local state directory; that is separate from this ADR.

### Extension points (Accepted)

- Writing: `SpoolSink` takes any `Transport`.
- Reading: drain uses a claim/ack/release interface; the directory is one implementation.
- The core does not ship Redis or other stores. Custom transports must stay fail-open (short
  timeouts, no exceptions into the app).

### Unix socket (Proposed — not implemented)

A stream unix socket per agent was sketched as a fallback when there is no writable disk:
non-blocking connect/write with a millisecond budget, ack after accept, mode `0660`, stale-socket
cleanup on agent start. **No `SocketTransport` exists in this repository.** Treat the bullets below
as a draft until code and fixtures land; do not mark them done in plans.

Draft behaviour if built later:

- One socket path per agent; `SOCK_STREAM`.
- **Framing (required on stream sockets):** messages are not self-delimiting. Proposed wire form:
  `u32` big-endian length, then exactly that many bytes of one TWSP v2 blob; then a one-byte
  ACK/NACK. Cap the max length; enforce deadlines; handle partial writes. Do not rely on
  "read until EOF" without an explicit half-close contract.
- Never block the request: drop and count (`Skipped`) on timeout.
- ACK after a full framed read means the blob is in the agent's memory, not that it is durable on
  disk or delivered to Pyroscope — document as best-effort unless a later ADR adds durability.
- Sidecar + `emptyDir` in Kubernetes, same as a spool directory (emptyDir/tmpfs loss on pod/node
  restart is acceptable for observability if stated in delivery guarantees).

## Consequences

- Parallel or restarted readers work without a coordinator; a dead reader delays files up to the
  stale-claim age.
- Shared fixtures should cover the claim protocol for non-PHP readers (PHP tests cover it today).
- The socket path, if built, is a second hand-off to maintain; spool remains the recommended setup.
  Socket framing must be fixed in a dedicated protocol doc before implementation.
