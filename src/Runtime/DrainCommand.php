<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Clock\Clock;
use Tracewick\Clock\SystemClock;
use Tracewick\Exception\LogicException;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Sink\PyroscopeSink;
use Tracewick\Sink\SpoolForwarder;
use Tracewick\Transport\CachingDnsResolver;
use Tracewick\Transport\DirectorySpoolSource;

/**
 * The `tracewick-drain` command: delivers a spool directory to Pyroscope from a process of its own.
 *
 * By default the command runs until it is stopped, for example under supervisor, systemd or as a
 * sidecar: it delivers files in batches, sleeps while the spool is empty and backs off while
 * the backend fails, doubling the pause from one second up to {@see MAX_BACKOFF_SECONDS}. With --once it makes a
 * single pass for cron. The --limit, --memory-limit and --time-limit options make it exit
 * cleanly so that its supervisor starts a fresh process; they are checked between passes, so a
 * file is never abandoned halfway. Nothing is lost on exit: undelivered files stay in the spool.
 *
 * Before each claim the default path prefetches DNS into a shared {@see CachingDnsResolver} so
 * resolution does not run mid-batch with files held. A failed pass forgets the cached address.
 *
 * {@see stop()} makes the command exit after the current file; bin/tracewick-drain calls it on
 * SIGTERM and SIGINT when ext-pcntl is available.
 */
final class DrainCommand
{
    public const MAX_BACKOFF_SECONDS = 30;

    /** Files claimed per pass (also bounded by {@see SpoolDrainer::DEFAULT_MAX_BATCH_INPUT_BYTES}). */
    public const BATCH_SIZE = 256;

    /** Exit code for a failed --once pass. */
    public const EXIT_FAILURE = 1;

    /** Exit code for invalid options. */
    public const EXIT_USAGE = 2;

    private const USAGE = <<<'TEXT'
        Usage: tracewick-drain --spool-dir=PATH --pyroscope-url=URL [options]

        Delivers the profiles in a Tracewick spool directory to Grafana Pyroscope.

        Options:
          --spool-dir=PATH         spool directory to read (env TRACEWICK_SPOOL_DIR)
          --pyroscope-url=URL      Pyroscope server URL (env TRACEWICK_PYROSCOPE_URL)
          --pyroscope-user=USER    basic authentication user (env TRACEWICK_PYROSCOPE_USER);
                                   the password is read from TRACEWICK_PYROSCOPE_PASSWORD only
          --pyroscope-tenant=ID    tenant sent as X-Scope-OrgID (env TRACEWICK_PYROSCOPE_TENANT)
          --reader-id=ID           name of this reader in claimed file names (default: host-pid)
          --once                   deliver what is in the spool, then exit
          --limit=COUNT            exit after handling COUNT files
          --memory-limit=SIZE      exit when the process uses more memory, such as 128M
          --time-limit=SECONDS     exit after running for SECONDS
          --sleep=SECONDS          pause while the spool is empty (default: 1)
          --aggregation-window=S   merge window for compact-stacks files, 10–15 (default: 15;
                                   env TRACEWICK_AGGREGATION_WINDOW)
          --help                   show this help

        TEXT;

    private const OPTIONS = ['spool-dir', 'pyroscope-url', 'pyroscope-user', 'pyroscope-tenant', 'reader-id', 'limit', 'memory-limit', 'time-limit', 'sleep', 'aggregation-window'];

    private const FLAGS = ['once', 'help'];

    private bool $stopping = false;

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /** @var \Closure(): int */
    private readonly \Closure $memoryUsage;

    /**
     * @param resource                                                                   $output        receives the summary
     * @param resource                                                                   $errors        receives usage errors, rejections and failures
     * @param ?\Closure(int): void                                                       $sleep         pauses for the given number of milliseconds
     * @param ?\Closure(): int                                                           $memoryUsage   returns the memory the process uses, in bytes
     * @param ?\Closure(PyroscopeConfig): SpoolForwarder                                  $forwarder     connects to the backend; a {@see PyroscopeSink} by default
     * @param ?\Closure(DrainSettings, SpoolForwarder): SpoolDrainer                      $createDrainer builds the drainer; default uses {@see DirectorySpoolSource}
     */
    public function __construct(
        private $output,
        private $errors,
        private readonly Clock $clock = new SystemClock(),
        ?\Closure $sleep = null,
        ?\Closure $memoryUsage = null,
        private readonly ?\Closure $forwarder = null,
        private readonly ?\Closure $createDrainer = null,
    ) {
        $this->sleep = $sleep ?? $this->pause(...);
        $this->memoryUsage = $memoryUsage ?? static fn(): int => memory_get_usage(true);
    }

    /**
     * @param list<string>          $arguments   command line arguments without the script name
     * @param array<string, string> $environment environment variables
     *
     * @return int the exit code
     */
    public function run(array $arguments, array $environment = []): int
    {
        try {
            $options = $this->parse($arguments);
            if (isset($options['help'])) {
                fwrite($this->output, self::USAGE);

                return 0;
            }

            $settings = DrainSettings::fromOptions($options, $environment);
        } catch (LogicException $e) {
            fwrite($this->errors, \sprintf("tracewick-drain: %s\nRun tracewick-drain --help for the options.\n", $e->getMessage()));

            return self::EXIT_USAGE;
        }

        $dns = new CachingDnsResolver(clock: $this->clock);
        $host = $settings->pyroscope->httpConfig()->host;
        $ownsDnsWarmup = !$this->forwarder instanceof \Closure && !$this->createDrainer instanceof \Closure;
        $forwarder = ($this->forwarder ?? (static fn(PyroscopeConfig $config): SpoolForwarder => new PyroscopeSink($config, dns: $dns)))($settings->pyroscope);
        $beforeClaim = $ownsDnsWarmup
            ? static fn(): bool => $dns->prefetch($host)
            : null;
        $drainer = ($this->createDrainer ?? (static fn(DrainSettings $settings, SpoolForwarder $forwarder): SpoolDrainer => new SpoolDrainer(
            new DirectorySpoolSource($settings->reader),
            $forwarder,
            $settings->aggregationWindow,
            beforeClaim: $beforeClaim,
        )))($settings, $forwarder);

        return $this->loop(
            $settings,
            $drainer,
            $ownsDnsWarmup ? static function () use ($dns, $host): void {
                $dns->forget($host);
            } : null,
        );
    }

    /**
     * Makes the command exit after the current file.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * @param ?\Closure(): void $forgetDns drops a cached VIP after a failed pass so the next prefetch re-resolves
     */
    private function loop(DrainSettings $settings, SpoolDrainer $drainer, ?\Closure $forgetDns = null): int
    {
        $startNs = $this->clock->nowNs();
        $delivered = 0;
        $rejected = 0;
        $ackFailures = 0;
        $backoff = 0;
        $exitCode = 0;

        while (!$this->stopping && !$this->limitReached($settings, $delivered + $rejected, $startNs)) {
            $handled = $delivered + $rejected;
            // Memory limits are checked between passes; keep passes to one file when set.
            $batchSize = null === $settings->memoryLimit ? self::BATCH_SIZE : 1;
            if (null !== $settings->limit) {
                // limitReached already ends the loop when handled >= limit; remaining is always > 0 here.
                $batchSize = min($batchSize, $settings->limit - $handled);
            }

            $result = $drainer->drain($batchSize, $settings->once);
            $delivered += $result->delivered;
            $rejected += $result->rejected;
            $ackFailures += $result->ackFailures;
            if (0 < $result->rejected) {
                fwrite($this->errors, \sprintf("Rejected %s\n", $result->lastRejection));
            }
            if (0 < $result->ackFailures) {
                fwrite($this->errors, \sprintf("Acknowledge failed for %d spool file(s) after delivery.\n", $result->ackFailures));
            }

            if ($result->delivered > 0 || $result->rejected > 0) {
                $backoff = 0;
            }

            if (DrainOutcome::LimitReached === $result->outcome) {
                continue;
            }

            if (DrainOutcome::Failed === $result->outcome) {
                if ($forgetDns instanceof \Closure) {
                    $forgetDns();
                }
                $backoff = 0 === $backoff ? 1 : min(self::MAX_BACKOFF_SECONDS, 2 * $backoff);
                fwrite($this->errors, \sprintf("Delivery failed: %s\n", $result->failure));
                if ($settings->once) {
                    $exitCode = self::EXIT_FAILURE;

                    break;
                }

                ($this->sleep)($backoff * 1_000);

                continue;
            }

            if ($settings->once) {
                break;
            }

            ($this->sleep)($settings->sleepSeconds * 1_000);
        }

        fwrite(
            $this->output,
            \sprintf("Delivered %d, rejected %d, ack-failures %d spool files.\n", $delivered, $rejected, $ackFailures),
        );

        return $exitCode;
    }

    private function limitReached(DrainSettings $settings, int $handled, int $startNs): bool
    {
        return (null !== $settings->limit && $handled >= $settings->limit)
            || (null !== $settings->memoryLimit && ($this->memoryUsage)() > $settings->memoryLimit)
            || (null !== $settings->timeLimitSeconds && $this->clock->nowNs() - $startNs >= $settings->timeLimitSeconds * 1_000_000_000);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array<string, string>
     */
    private function parse(array $arguments): array
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (1 !== preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $argument, $match)) {
                throw new LogicException(\sprintf('Unexpected argument "%s".', $argument));
            }

            $name = $match[1];
            $value = $match[2] ?? null;
            if (\in_array($name, self::FLAGS, true) && null === $value) {
                $options[$name] = '';
            } elseif (\in_array($name, self::OPTIONS, true) && null !== $value) {
                $options[$name] = $value;
            } else {
                throw new LogicException(\sprintf('Unknown option "%s".', $argument));
            }
        }

        return $options;
    }

    /**
     * Sleeps in short steps, so that a signal stops the command without waiting for the pause to end.
     */
    private function pause(int $milliseconds): void
    {
        for ($left = $milliseconds; $left > 0 && !$this->stopping; $left -= 100) {
            usleep(1_000 * min(100, $left));
        }
    }
}
