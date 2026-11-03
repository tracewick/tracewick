# 9. Handing profiles to a local agent: spool readers (and a proposed unix socket)

- Status: Accepted (spool readers); Proposed (unix socket)
- Date: 2026-10-29
- Updated: 2026-11-01

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
- Claim: rename `X.spool` → `.X.spool.claimed-<reader id>` in the same directory. One winner;
  others see "not found".
- On success, delete. On retryable failure, rename back. Invalid files go to `rejected/` as in
  [`docs/spool-format.md`](../spool-format.md).
- Claimed files older than a configured age (default 10 minutes) are returned to the queue.
- Writers ignore dotfiles; claimed files do not count toward `max_files`.
- **Filesystem:** the spool directory and reader claim protocol assume a **local** filesystem.
  Do not put the spool on NFS or other network volumes: `rename` semantics and durability differ.
  Prefer a dedicated volume or tmpfs on the same host as the writer.
- `flock()` is not part of the claim protocol. Circuit-breaker / diagnostic state files may use
  locking on a local state directory; that is separate from this ADR.

### Extension points (Accepted)

- Writing: `SpoolSink` takes any `Transport`.
- Reading: drain uses a claim/ack/release interface; the directory is one implementation.
- The core does not ship Redis or other stores. Custom transports must stay fail-open (short
  timeouts, no exceptions into the app).

### Unix socket (Proposed — not implemented)

A stream unix socket per agent was sketched as a fallback when there is no writable disk:
non-blocking connect/write with a millisecond budget, one-byte ack, mode `0660`, stale-socket
cleanup on agent start. **No `SocketTransport` exists in this repository.** Treat the bullets below
as a draft until code and fixtures land; do not mark them done in plans.

Draft behaviour if built later:

- One socket path per agent; `SOCK_STREAM`; message = one spool file blob.
- Never block the request: drop and count (`Skipped`) on timeout.
- Ack after a full read; no durability beyond the agent's memory.
- Sidecar + `emptyDir` in Kubernetes, same as a spool directory.

## Consequences

- Parallel or restarted readers work without a coordinator; a dead reader delays files up to the
  stale-claim age.
- Shared fixtures should cover the claim protocol for non-PHP readers (PHP tests cover it today).
- The socket path, if built, is a second hand-off to maintain; spool remains the recommended setup.
