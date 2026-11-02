<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Encoder\CompactStacksCodec;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\ProfileMerger;
use Tracewick\Sink\SpoolAggregationKey;
use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolFileName;
use Tracewick\Sink\SpoolForwarder;
use Tracewick\Transport\ClaimedSpoolFile;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;
use Tracewick\Transport\SpoolSource;

/**
 * Delivers the files of a spool to a backend, one pass at a time.
 *
 * Compact-stacks files that share an aggregation key and the same wall-clock window id are merged
 * into one pprof POST ([ADR 12](../../docs/adr/0012-aggregate-before-delivery.md)). The window id
 * is merge compatibility only: open windows are sent immediately (multi-POST per window is OK).
 * Invalid files are rejected; a failed send releases every claimed file still held and ends the
 * pass.
 *
 * The drainer runs in a process of its own, never inside a request, and never throws.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final readonly class SpoolDrainer
{
    /** Default claim budget: two writer max-file sizes. */
    public const DEFAULT_MAX_BATCH_INPUT_BYTES = 8_388_608;

    private CompactStacksCodec $codec;

    private ProfileMerger $merger;

    public function __construct(
        private SpoolSource $source,
        private SpoolForwarder $forwarder,
        private SpoolAggregationWindow $window = new SpoolAggregationWindow(),
        private int $maxBatchInputBytes = self::DEFAULT_MAX_BATCH_INPUT_BYTES,
    ) {
        $this->codec = new CompactStacksCodec();
        $this->merger = new ProfileMerger();
    }

    /**
     * @param int  $maxFiles           the most files to claim in this pass
     * @param bool $includeOpenWindows kept for call-site compatibility; the window is no longer a
     *                                 send gate (always included)
     */
    public function drain(int $maxFiles = \PHP_INT_MAX, bool $includeOpenWindows = false): DrainResult
    {
        unset($includeOpenWindows);

        if ($maxFiles <= 0) {
            return new DrainResult(DrainOutcome::LimitReached, 0, 0);
        }

        $delivered = 0;
        $rejected = 0;
        $ackFailures = 0;
        $lastRejection = '';
        /** @var list<ClaimedSpoolFile> $held */
        $held = [];
        $batch = [];
        $hitBudget = false;

        try {
            [$batch, $hitBudget] = $this->claimBatch($maxFiles);
            if ([] === $batch) {
                return new DrainResult(DrainOutcome::Empty, 0, 0);
            }

            $held = $batch;
            /** @var array<string, non-empty-list<array{ClaimedSpoolFile, SpoolFile, Profile}>> $groups */
            $groups = [];

            foreach ($batch as $claimed) {
                try {
                    $file = SpoolFile::fromBytes($claimed->bytes);
                    $profile = $this->profileFromCompact($file);
                } catch (\Throwable $e) {
                    $this->source->reject($claimed);
                    $held = $this->without($held, $claimed);
                    ++$rejected;
                    $lastRejection = \sprintf('%s: %s', $claimed->name, $e->getMessage());

                    continue;
                }

                $windowId = $this->window->idFor($file->startNs);
                $groupKey = $this->groupKey($claimed->name, $file) . "\0" . $windowId;
                $groups[$groupKey][] = [$claimed, $file, $profile];
            }

            foreach ($groups as $members) {
                $claimedFiles = [];
                $profiles = [];
                foreach ($members as [$claimed, $file, $profile]) {
                    $claimedFiles[] = $claimed;
                    $profiles[] = $profile;
                }
                $first = $members[0][1];

                try {
                    $merged = $this->merger->merge($profiles);
                    $spool = new SpoolFile(
                        $first->application,
                        $first->eventType,
                        $first->periodNs,
                        $merged->startNs,
                        $merged->endNs,
                        $first->labels,
                        SpoolFile::FORMAT_COMPACT_STACKS,
                        $this->codec->encode($merged),
                        SpoolFile::VERSION_2,
                    );
                    $delivery = $this->forwardFile($spool);
                } catch (\Throwable $e) {
                    $delivery = Delivery::failed($e->getMessage());
                }

                if ($delivery->isDelivered()) {
                    foreach ($claimedFiles as $claimed) {
                        if (!$this->source->acknowledge($claimed)) {
                            ++$ackFailures;
                        }
                        $held = $this->without($held, $claimed);
                        ++$delivered;
                    }

                    continue;
                }

                if (
                    DeliveryStatus::Rejected === $delivery->status
                    || DeliveryStatus::Configuration === $delivery->status
                ) {
                    foreach ($claimedFiles as $claimed) {
                        $this->source->reject($claimed);
                        $held = $this->without($held, $claimed);
                        ++$rejected;
                    }
                    $lastRejection = \sprintf('%s: %s', $members[0][0]->name, $delivery->reason);

                    continue;
                }

                $this->releaseAll($held);

                return new DrainResult(DrainOutcome::Failed, $delivered, $rejected, $lastRejection, $delivery->reason, $ackFailures);
            }
        } catch (\Throwable $e) {
            $this->releaseAll($held);

            return new DrainResult(DrainOutcome::Failed, $delivered, $rejected, $lastRejection, $e->getMessage(), $ackFailures);
        }

        $this->releaseAll($held);

        // A full claim batch (files or bytes) means more files may remain.
        if ($hitBudget || \count($batch) >= $maxFiles) {
            return new DrainResult(DrainOutcome::LimitReached, $delivered, $rejected, $lastRejection, ackFailures: $ackFailures);
        }

        return new DrainResult(DrainOutcome::Empty, $delivered, $rejected, $lastRejection, ackFailures: $ackFailures);
    }

    /**
     * @return array{0: list<ClaimedSpoolFile>, 1: bool}
     */
    private function claimBatch(int $maxFiles): array
    {
        $batch = [];
        $bytes = 0;
        $hitBudget = false;

        for ($i = 0; $i < $maxFiles; ++$i) {
            $claimed = $this->source->claim();
            if (!$claimed instanceof ClaimedSpoolFile) {
                break;
            }

            $size = \strlen($claimed->bytes);
            if ([] !== $batch && $bytes + $size > $this->maxBatchInputBytes) {
                $this->source->release($claimed);
                $hitBudget = true;

                break;
            }

            $batch[] = $claimed;
            $bytes += $size;
            if ($bytes >= $this->maxBatchInputBytes) {
                $hitBudget = true;

                break;
            }
        }

        return [$batch, $hitBudget];
    }

    private function forwardFile(SpoolFile $file): Delivery
    {
        try {
            return $this->forwarder->forward($file);
        } catch (\Throwable $e) {
            return Delivery::failed($e->getMessage());
        }
    }

    private function profileFromCompact(SpoolFile $file): Profile
    {
        $parts = $this->codec->decode($file->payload);

        return new Profile(
            $file->eventType,
            $file->periodNs,
            $file->startNs,
            $file->endNs,
            $parts->frames,
            $parts->stacks,
            new Labels($file->labels),
        );
    }

    private function groupKey(string $name, SpoolFile $file): string
    {
        return SpoolFileName::aggregationKeyHash($name)
            ?? SpoolAggregationKey::hash($file->application, $file->labels, $file->eventType, $file->periodNs);
    }

    /**
     * @param list<ClaimedSpoolFile> $held
     *
     * @return list<ClaimedSpoolFile>
     */
    private function without(array $held, ClaimedSpoolFile $remove): array
    {
        return array_values(array_filter(
            $held,
            static fn(ClaimedSpoolFile $claimed): bool => $claimed->handle !== $remove->handle,
        ));
    }

    /**
     * @param list<ClaimedSpoolFile> $files
     */
    private function releaseAll(array $files): void
    {
        foreach ($files as $claimed) {
            $this->source->release($claimed);
        }
    }
}
