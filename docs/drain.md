# Draining the spool with `tracewick-drain`

`tracewick-drain` delivers the profiles that an application wrote to a
[spool directory](spool-format.md) to Grafana Pyroscope. It runs as a process of its own, so the
application only ever writes local files, and never waits for the network
([ADR 5](adr/0005-fail-open-policy.md), [ADR 7](adr/0007-language-neutral-spool-format.md)).

It is written in PHP and ships with the library, so it works without `tracewick-agent`. Several
drains, or a drain and an agent, can read the same directory at the same time; each file goes to
exactly one of them ([ADR 9](adr/0009-agent-hand-off.md)).

`PyroscopeSink::forward()` encodes pprof from compact spool payloads
([ADR 13](adr/0013-spool-v2-compact-payload.md)). Files that share an aggregation key and the same
window id are merged before upload ([ADR 12](adr/0012-aggregate-before-delivery.md)). The window is
merge compatibility only: drain does not wait for it to close, and may send more than one POST for
the same key in the same window. Each pass claims under a file count and a byte budget.

## DNS before claim

PHP cannot bound `gethostbynamel()` with the HTTP socket timeout ([ADR 8](adr/0008-own-http-transport.md)).
The default `tracewick-drain` path therefore:

1. Resolves the Pyroscope host **once before claiming** any spool files in a pass.
2. Caches a successful IP for about 60 seconds and reuses it for POSTs in that process (TLS
   `Host` / SNI still use the original hostname).
3. Skips the pass without claiming when resolution fails, so files are not held across a DNS hang.
4. Drops the cached address after a failed delivery pass so the next attempt can pick up a new
   VIP or pod IP (Kubernetes / Swarm service failover).

Application workers on the spool path never resolve or send HTTP. Keep-alive is not added to the
PHP client; a future agent may own connection reuse instead.

## What it does with each file

The drain claims a batch (oldest first), merges compatible compact-stacks files, then sends:

| Result                                                            | What happens to the file                         |
|-------------------------------------------------------------------|--------------------------------------------------|
| Delivered                                                         | Deleted                                          |
| Invalid, or refused by Pyroscope (4xx other than 408 and 429)     | Moved to `rejected/` and reported on stderr      |
| Pyroscope unreachable or failing (408, 429, 5xx, timeouts)        | Returned to the spool for the next try           |

After a failed delivery the drain pauses before trying again: one second, then twice as long
after each further failure, up to 30 seconds. One successful delivery resets the pause. While the
spool is empty it checks again every `--sleep` seconds.

Files carry no attempt counter yet, so a file is never given up because of failed attempts. A
file that Pyroscope keeps failing on with a 5xx or a timeout, rather than refusing it, is retried
until it succeeds and holds back the files behind it. If the drain reports the same failure for
a long time while Pyroscope is otherwise healthy, move the oldest file out of the spool directory
by hand. Writers stop adding files when the directory is full, so the application is not affected.

The drain keeps no state of its own: whatever it has not delivered stays in the spool directory,
so it can be stopped and restarted at any time. If it dies while sending a file, any reader returns
that file to the spool after 10 minutes.

If a file was accepted by Pyroscope but could not be deleted from the spool (acknowledge failure),
the pass still counts it as delivered, reports `ack-failures` on the summary line, and may
re-deliver that file later.

## Usage

```bash
vendor/bin/tracewick-drain --spool-dir=/var/spool/tracewick --pyroscope-url=http://pyroscope:4040
```

| Option                  | Environment variable           | Meaning                                                        |
|-------------------------|--------------------------------|----------------------------------------------------------------|
| `--spool-dir=PATH`      | `TRACEWICK_SPOOL_DIR`          | Spool directory to read; required                              |
| `--pyroscope-url=URL`   | `TRACEWICK_PYROSCOPE_URL`      | Pyroscope server URL; required                                 |
| `--pyroscope-user=USER` | `TRACEWICK_PYROSCOPE_USER`     | Basic authentication user                                      |
|                         | `TRACEWICK_PYROSCOPE_PASSWORD` | Basic authentication password, from the environment only       |
| `--pyroscope-tenant=ID` | `TRACEWICK_PYROSCOPE_TENANT`   | Tenant, sent as `X-Scope-OrgID`                                |
| `--reader-id=ID`        |                                | Name of this reader in claimed file names; default `host-pid`  |
| `--once`                |                                | Deliver what is in the spool, then exit                        |
| `--limit=COUNT`         |                                | Exit after handling COUNT files                                |
| `--memory-limit=SIZE`   |                                | Exit when the process uses more memory, such as `128M`         |
| `--time-limit=SECONDS`  |                                | Exit after running for SECONDS                                 |
| `--sleep=SECONDS`       |                                | Pause while the spool is empty; default 1                      |
| `--aggregation-window=S`| `TRACEWICK_AGGREGATION_WINDOW` | Merge window for compact-stacks files, 10–15; default 15       |

An option wins over its environment variable. The password has no option, so that it does not
show up in the process list.

`--limit`, `--memory-limit` and `--time-limit` are checked between files, so a file is never left
halfway. They let the process exit cleanly and be started afresh by its supervisor, which bounds
the effect of memory leaks in long-running PHP processes.

### Exit codes

| Code | Meaning                                                              |
|------|----------------------------------------------------------------------|
| 0    | Stopped by a signal or a limit, or `--once` emptied the spool        |
| 1    | `--once` stopped at a failed delivery; the files wait for the next run |
| 2    | Invalid options                                                      |

On SIGTERM or SIGINT the drain finishes the current file and exits. This needs ext-pcntl; without
it the signal ends the process at once, and the file it was sending returns to the spool after 10
minutes. If Pyroscope had already accepted that file, it is then delivered a second time.

## Running it

### supervisor

```ini
[program:tracewick-drain]
command=/var/www/app/vendor/bin/tracewick-drain --spool-dir=/var/spool/tracewick --pyroscope-url=http://pyroscope:4040 --memory-limit=128M --time-limit=3600
user=www-data
autorestart=true
stopsignal=TERM
stopwaitsecs=30
```

### systemd

```ini
[Unit]
Description=Tracewick spool drain
After=network-online.target

[Service]
User=www-data
Environment=TRACEWICK_SPOOL_DIR=/var/spool/tracewick
Environment=TRACEWICK_PYROSCOPE_URL=http://pyroscope:4040
ExecStart=/var/www/app/vendor/bin/tracewick-drain --memory-limit=128M --time-limit=3600
Restart=always
KillSignal=SIGTERM
TimeoutStopSec=30

[Install]
WantedBy=multi-user.target
```

### cron

```cron
* * * * * www-data /var/www/app/vendor/bin/tracewick-drain --once --time-limit=50 --spool-dir=/var/spool/tracewick --pyroscope-url=http://pyroscope:4040
```

With cron, `--time-limit` below the cron interval keeps runs from piling up; if they do overlap,
the runs share the files without delivering any of them twice.

Default HTTP timeouts are 200 ms connect / 1 s total (same as request-path). If Pyroscope is often
slower than that on your network, raise them on `PyroscopeConfig` for the drain process only.

`stopwaitsecs` and `TimeoutStopSec` must be longer than one delivery, which the HTTP timeouts
bound to a few seconds.

## From PHP

The command is a thin loop around `Tracewick\Runtime\SpoolDrainer`, which a worker of your own can
use directly:

```php
use Tracewick\Runtime\SpoolDrainer;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Sink\PyroscopeSink;
use Tracewick\Transport\DirectorySpoolSource;
use Tracewick\Transport\SpoolReaderConfig;

$drainer = new SpoolDrainer(
    new DirectorySpoolSource(new SpoolReaderConfig('/var/spool/tracewick')),
    new PyroscopeSink(new PyroscopeConfig('http://pyroscope:4040', 'drain')),
);

$result = $drainer->drain(); // one pass: until the spool is empty, a budget is hit, or a delivery fails
```

`drain()` never throws. Its result tells how many files were delivered and rejected, acknowledge
failures after a successful send, and whether the pass ended because the spool was empty, because
of a claim budget, or because a delivery failed and the caller should wait before the next pass.
