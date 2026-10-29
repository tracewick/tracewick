# 7. Language-neutral spool format for the agent

- Status: Accepted
- Date: 2026-10-29

## Context

The recommended high-load setup writes profiles to local storage within microseconds and lets a
separate process deliver them (see [ADR 5](0005-fail-open-policy.md)). That process is
`tracewick-agent`, planned in Go, but it could also be Grafana Alloy, a sidecar written by a user,
or a future agent in another language. If the hand-off format were PHP-specific (for example
`serialize()`), only PHP could read it.

## Decision

- The hand-off between the library and the agent is a **spool directory** with one file per
  profile. The format is documented in `docs/spool-format.md` and versioned; it is a public
  contract, independent of the PHP API.
- A file contains a small, versioned metadata header (application name, labels, time range, sample
  type and period, encoding) and the profile payload in pprof
  ([ADR 4](0004-pprof-as-primary-format.md)). The exact byte layout is specified with the first
  implementation in phase 3.
- Writers create a file under a temporary name in the same directory and `rename()` it into place,
  so readers never see partial files.
- The spool has a maximum size and file count. A writer that would exceed them drops the profile
  and counts the drop; it never blocks and never deletes other files.
- The library and the agent are tested against the same set of fixture files.

## Consequences

- Any language can implement a reader or writer from the spec and the fixtures.
- Changing the format requires a new format version that readers must support side by side with
  the old one for at least one minor release.
- A spool directory depends on a local writable filesystem; for environments without one, a local
  socket transport can carry the same file contents.
