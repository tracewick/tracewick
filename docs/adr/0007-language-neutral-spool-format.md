# 7. Language-neutral spool format for the agent

- Status: Accepted
- Date: 2026-10-29
- Updated: 2026-11-01

## Context

The recommended high-load setup writes profiles to a local directory and lets a separate process
deliver them ([ADR 5](0005-fail-open-policy.md)). That process may be `tracewick-drain` (PHP, exists),
a future Go `tracewick-agent`, Alloy, or a custom sidecar. A PHP-only format (`serialize()`) would
lock readers to PHP.

## Decision

- Hand-off is a **spool directory**, one file per profile. Format: `docs/spool-format.md`,
  versioned, independent of the PHP API.
- File = versioned header + payload. The supported version is **v2**: compact stack→weight
  payload; pprof is produced in drain/agent ([ADR 13](0013-spool-v2-compact-payload.md),
  [ADR 4](0004-pprof-as-primary-format.md)). Layout stays in `docs/spool-format.md`.
- Writers write under a temporary name and `rename()` into place.
- Max size and file count; on overflow drop and count; never block waiting for a reader.
- PHP tests use shared fixtures under `tests/Fixtures/spool/v2/`. A Go agent, when it exists,
  should use the same fixtures; that is not wired yet.
- Directory must be local FS ([ADR 9](0009-agent-hand-off.md)).

## Consequences

- Other languages can implement readers from the spec and fixtures.
- Format changes need a new version. Dual-read of older versions is not promised until Tracewick
  has public installs that need a migration window.
- No writable local disk → proposed unix socket ([ADR 9](0009-agent-hand-off.md)), not implemented.
