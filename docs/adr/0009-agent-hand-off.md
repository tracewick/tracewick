# 9. Handing profiles to a local agent: spool readers and the unix socket

- Status: Accepted
- Date: 2026-10-29

## Context

[ADR 5](0005-fail-open-policy.md) keeps network delivery out of the request, and
[ADR 7](0007-language-neutral-spool-format.md) defines the spool directory through which the
application hands profiles to a separate delivery process: `tracewick-agent`, a PHP drain command,
Grafana Alloy or a process written by a user. ADR 7 leaves several questions open:

- How many processes read one spool, and what happens when two of them pick the same file. In
  practice there is one agent per host or pod, but restarts overlap, a cron-driven drain can
  start while the previous run is still busy, and some users will run several readers for
  throughput.
- Whether the storage can be replaced, for example by Redis, without forking the library.
- How the local socket transport mentioned in ADR 7 for environments without a writable file
  system behaves, given that a socket, unlike a file, can block the writer.

UDP was considered and rejected: a profile is tens to hundreds of kilobytes, more than fits in a
datagram, and datagrams are dropped silently.

## Decision

### Spool: any number of readers

- The spool directory stays the primary hand-off. Readers coordinate through the file system
  only, without locks or a coordinator.
- A reader claims a file by renaming `X.spool` to `.X.spool.claimed-<reader id>` in the same
  directory. `rename()` within one directory is atomic, so exactly one reader wins; the others
  get "file not found" and move on to the next file.
- The winner delivers the payload and deletes the file. When delivery fails for a reason that may
  pass, it renames the file back to `X.spool`. A file that is invalid or in an unsupported format
  is moved aside instead, as `docs/spool-format.md` describes.
- A claimed file older than a configurable age (default 10 minutes) belongs to a reader that
  died; any reader renames it back to `X.spool`.
- Claimed files start with a dot, so writers already ignore them and do not count them towards
  the file limit; the version 1 file format does not change. The claim protocol is added to
  `docs/spool-format.md` together with the first reader.
- `flock()` is not used: it is unreliable on NFS and other network volumes, where `rename()` within
  one directory remains atomic.

### Extension points instead of more stores in the core

- Writing: `SpoolSink` accepts any `Transport`, so a user can store the same spool file bytes in
  Redis or elsewhere with a transport of their own.
- Reading: the PHP drain reads through an interface (claim, acknowledge, return to the queue), with
  the directory as one implementation, so it works with any store a user adds.
- The core ships no Redis or other store implementations, which would bring dependencies
  ([minimal dependencies](../../AGENTS.md)). `tracewick-agent` reads the spool directory and,
  later, the unix socket; other stores need their own reader.
- Custom transports must follow ADR 5 like the built-in ones: bounded queues, short timeouts and
  no exceptions into the application. A store that answers in two seconds would otherwise bring
  the delay back into the request. The documentation says so where it describes custom transports.

### Unix socket: one per agent, never blocking the request

- **One socket per agent instance.** Each agent process listens on its own path, such as
  `/run/tracewick/agent.sock`, and the application on that host or pod is configured with the path
  of its agent. Only one process can listen on a path; a second agent gets `EADDRINUSE`. There is
  no socket shared by several agents, and therefore no race between readers.
- **Streams, not datagrams.** The socket is `SOCK_STREAM`. Every connection is private to one PHP
  worker, so messages of different workers never interleave. Each message is exactly a spool
  file, whose header already carries the payload size, so no second framing format is needed.
- **Never block the request.** If the agent is alive but slow, the listen queue and the socket
  buffer fill up and `connect()` or `write()` would wait. The transport connects and writes in
  non-blocking mode with a time limit of a few milliseconds; when the limit is reached, the profile
  is dropped and counted (`Skipped`), as with a full spool.
- **Acknowledgement.** After reading a whole message the agent answers with one byte. Only then
  does the transport report `Delivered`; a connection closed earlier means `Failed`. This costs
  one local round trip, measured in microseconds, and makes the result honest.
- **No durability.** What the agent has accepted lives in its memory and is lost if it crashes.
  This is why the socket is the fallback for environments without a writable file system, not the
  recommended setup; the agent bounds its in-memory queue and drops and counts beyond it.
- **Stale socket files.** A crashed agent leaves its socket file behind. On start, the agent tries
  to connect to the path: if nobody answers, it removes the file and listens; if someone does,
  another agent is running and it refuses to start.
- **Permissions.** The socket is created with mode `0660` and a group shared with the PHP-FPM user,
  so that only the application can write to it.
- **Scope.** A unix socket works only within one host; in Kubernetes the agent runs as a sidecar
  and shares the socket through an `emptyDir` volume, like the spool directory.
- **Connections.** The first implementation opens one connection per profile, which costs tens of
  microseconds. A persistent connection per worker is cheaper but must handle the agent
  restarting; it is considered only if measurements show the need.

## Consequences

- Spool readers can be added, restarted or run in parallel without configuration, and a reader
  crash loses no profile, at the cost of a delay of up to the stale-claim age for the files it held.
- Readers in any language implement the same claim protocol; the shared fixtures gain cases for it.
- Users with other stores have clear extension points, and the core stays free of their
  dependencies; such stores are not readable by `tracewick-agent` out of the box.
- The socket transport adds a second hand-off path to maintain and test, including a slow and a
  dead agent. Its acknowledgement and time limits keep it within ADR 5, but it trades durability
  for not needing a disk.
