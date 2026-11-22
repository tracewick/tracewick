# Operating Tracewick

Use this checklist when deploying or upgrading the profiler. It describes the existing
spool/delivery path, not a production-readiness certification. Pin versions and use the
[compatibility policy](compatibility.md); benchmark results apply to their recorded versions
and workloads, not automatically to your application.

## Before enabling collection

- [ ] **Check the application runtime.** Follow [installation](install-excimer.md) and confirm
  Excimer is loaded in the actual FPM pool or worker container. `php --ri excimer` checks that
  CLI interpreter only. Installation without Excimer succeeds but collects nothing.
- [ ] **Choose one lifecycle.** Use [UnitOfWorkProfiler](runtime.md#unit-of-work-profiler) for
  sequential work, created once per long-running worker, with stop/flush in `finally`.
  Use [ContinuousProfiler](runtime.md#continuous-profiler) for concurrent work and schedule
  `pulse()` outside the sampling callback. Do not combine prepend with a second profiler for
  the same request. Construct/start profiling after fork/exec, never in a forking parent.
- [ ] **Keep delivery outside request workers.** Use `SpoolSink` and one normal reader per
  spool. Prefer the [Go agent](https://github.com/tracewick/agent#readme) for a long-running
  sidecar with HTTP connection reuse. [PHP drain](drain.md) ships with the library for
  cron/`--once` and as a reference implementation. Do not run both against the same spool
  as the normal deployment.
- [ ] **Share the same local volume and identity.** Check the writer and reader see the same
  files, not separate container directories. Prefer the same numeric UID: writer-created
  directories are `0700` and files `0600`, so matching GIDs alone do not grant access.
  Check shard directories too; do not solve this with world-writable permissions.
- [ ] **Bound storage and decoding.** Use a dedicated volume/quota or size-limited tmpfs.
  Spool admission is soft; `maxFiles × maxFileBytes` is not a hard disk ceiling. Monitor free
  bytes and inodes, and keep PHP/Go decode limits compatible with the writer configuration.
- [ ] **Start with deliberate sampling and labels.** UoW defaults to a 0.1 share of units,
  not a 0.1 sampling frequency. Use route names/command names, not raw URLs, user IDs or
  secrets. Built-in label filtering is not a complete data-scrubbing policy; profiles also
  contain function/file names. Cardinality limits default to process/drain scope, not host-wide.
- [ ] **Provide configuration to each process.** The library does not load `.env` files.
  The standalone PHP reader uses `getenv()`, not another application's `$_ENV`; use its
  [CLI/env rules](drain.md#usage). Symfony resolves its own `%env(...)%` settings separately.
  Confirm the reader's backend URL, credentials and tenant without logging secret values.
- [ ] **Verify the complete path.** Exercise a known request/job, check that collection and
  spool writes happen, then verify the expected service, labels and time range in Pyroscope.
  At a 0.1 share, one missing request profile is expected; use enough work for the check.
- [ ] **Check operational recovery.** Test disabling/restarting on a canary, backend-failure
  handling and rollback. Track worker CPU/RSS, latency and profile loss against your baseline.
  Fail-open does not make disk I/O free or recover a native Excimer fork deadlock.

## Observe collection, spool and delivery separately

[Profiler diagnostics](runtime.md#diagnostics) are local to that profiler's lifetime. With
`SpoolSink`, `deliveredProfiles` means written to the spool, **not accepted by Pyroscope**.
Use reader logs/results and the backend to confirm delivery. A healthy application response or
an empty spool alone does not prove that profiles reached the backend.

Inspect the spool from the reader's container/user, using its real mount path:

```bash
SPOOL=/var/spool/tracewick
id
ls -ld "$SPOOL"
df -h "$SPOOL"
df -i "$SPOOL"
# Pending files, excluding the rejected archive; includes shard directories.
find "$SPOOL" -path "$SPOOL/rejected" -prune -o -type f -name '*.spool' -print | wc -l
```

Compare the backlog count/oldest pending age over time with incoming workload. Inspect
`rejected/` and hidden files containing `.claimed-` separately. A claim can belong to a live reader:
do not rename or delete it to force a retry. PHP's default stale-claim age is 10 minutes;
use the configured age for the actual reader. Retried delivery can duplicate an accepted profile.

## Troubleshooting

| Symptom | Check / action |
| --- | --- |
| No profiles and no spool files | Check `inactiveSampler`/startup warning, effective kill-switch settings in the application process, Excimer in that SAPI, sample rate and lifecycle calls. `emptyProfiles` can mean work shorter than a sampling period. |
| Collection works, but writes fail or are skipped | Check writer permissions, mount, free bytes/inodes and spool admission limits. A skipped profile is lost, not queued elsewhere. Preserve a small diagnostic sample before clearing space. |
| Files accumulate | Check that the single intended reader is running on the same volume, can rename/delete files, and can reach/authenticate to the backend. Read its failure reason; do not add readers or increase disk limits as the first fix. |
| `rejected/` grows | Inspect the reader's rejection reason: malformed/oversized spool, incompatible decode limits or backend rejection. PHP and Go have different HTTP error policies; use the selected reader's docs. Do not blindly move rejected files back. |
| Reader reports delivery but files return | Check `ack-failures` and delete/rename permissions. A send can succeed before a crash or failed acknowledgement; delivery is at least once. |
| Worker RSS grows or profiles disappear during long jobs | Ensure one profiler/sink per sequential worker, regular stop/flush, and duration/buffer limits. UoW automatic rotation buffers profiles; it does not send from the callback. Continuous mode needs loop-driven pulse/flush. |
| Worker hangs around fork | Follow [fork restrictions](runtime.md#forking-workers). Start profiling only in the final child or fresh interpreter; stop-before-fork and PHP exception handling do not establish native timer safety. |
| A profile is missing or has unexpected labels | Check service/tenant/time range, sampling/empty/drop diagnostics, label policy and `cardinality=other`. Spool success is not backend success; merged profiles are not individual request traces. |

## Disable collection and roll back

1. Set `TRACEWICK_ENABLED=0` in the **application service's** configuration and recreate/restart
   its workers. Merely exporting it in an administrator shell does not change running services.
   Check for a higher-priority `$_SERVER`/`$_ENV` value; see [precedence](runtime.md#unit-of-work-profiler).
2. Alternatively, provision the regular file named by `TRACEWICK_DISABLE_FILE` before the
   profiler is constructed. Its existence is cached. Touching/removing it is not a live toggle
   for an existing profiler or long-running worker; recycle workers when changing it.
3. Verify the new workers report disabled collection and stop producing new profiles. The
   reader can continue draining existing files. To stop delivery too, stop the reader separately;
   doing so while writers remain enabled grows the backlog until admission starts dropping.
4. Restore the previous locked application package/configuration and compatible reader version,
   following the [upgrade/format policy](compatibility.md#cli-and-spool-interoperability).
   Keep the spool volume; do not let an older reader reject files in a format it cannot read.
5. Confirm application health first, then re-enable on a canary and check the complete path
   before widening rollout. Restrict access to retained profiles and backend credentials.
