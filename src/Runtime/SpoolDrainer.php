<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolFileException;
use Tracewick\Sink\SpoolForwarder;
use Tracewick\Transport\ClaimedSpoolFile;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;
use Tracewick\Transport\SpoolSource;

/**
 * Delivers the files of a spool to a backend, one pass at a time.
 *
 * Each file is claimed, validated and forwarded, then acknowledged when it was delivered,
 * rejected when it is invalid or the backend refused it, and released when the delivery failed
 * for a reason that may pass. A pass ends when the spool is empty, after the given number of
 * files, or at the first failed or skipped delivery: a backend that is down would otherwise get
 * every file of the spool in turn, each with its own timeout. The caller decides how long to
 * wait before the next pass.
 *
 * The drainer runs in a process of its own, never inside a request, and never throws.
 *
 * @see https://github.com/tracewick/tracewick/blob/main/docs/spool-format.md
 */
final readonly class SpoolDrainer
{
    public function __construct(
        private SpoolSource $source,
        private SpoolForwarder $forwarder,
    ) {}

    /**
     * @param int $maxFiles the most files to handle in this pass
     */
    public function drain(int $maxFiles = \PHP_INT_MAX): DrainResult
    {
        $delivered = 0;
        $rejected = 0;
        $lastRejection = '';

        try {
            for ($handled = 0; $handled < $maxFiles; ++$handled) {
                $claimed = $this->source->claim();
                if (!$claimed instanceof ClaimedSpoolFile) {
                    return new DrainResult(DrainOutcome::Empty, $delivered, $rejected, $lastRejection);
                }

                $delivery = $this->forward($claimed);
                if ($delivery->isDelivered()) {
                    $this->source->acknowledge($claimed);
                    ++$delivered;
                } elseif (
                    DeliveryStatus::Rejected === $delivery->status
                    || DeliveryStatus::Configuration === $delivery->status
                ) {
                    $this->source->reject($claimed);
                    ++$rejected;
                    $lastRejection = \sprintf('%s: %s', $claimed->name, $delivery->reason);
                } else {
                    $this->source->release($claimed);

                    return new DrainResult(DrainOutcome::Failed, $delivered, $rejected, $lastRejection, $delivery->reason);
                }
            }
        } catch (\Throwable $e) {
            return new DrainResult(DrainOutcome::Failed, $delivered, $rejected, $lastRejection, $e->getMessage());
        }

        return new DrainResult(DrainOutcome::LimitReached, $delivered, $rejected, $lastRejection);
    }

    private function forward(ClaimedSpoolFile $claimed): Delivery
    {
        try {
            return $this->forwarder->forward(SpoolFile::fromBytes($claimed->bytes));
        } catch (SpoolFileException $e) {
            return Delivery::rejected($e->getMessage());
        } catch (\Throwable $e) {
            return Delivery::failed($e->getMessage());
        }
    }
}
