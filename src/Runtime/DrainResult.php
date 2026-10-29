<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

/**
 * What one pass of {@see SpoolDrainer} did.
 */
final readonly class DrainResult
{
    /**
     * @param DrainOutcome $outcome       why the pass ended
     * @param int          $delivered     files delivered and removed from the spool
     * @param int          $rejected      files moved aside because they are invalid or were refused
     * @param string       $lastRejection the reason the last file was rejected, or an empty string
     * @param string       $failure       the reason the pass ended with {@see DrainOutcome::Failed}, or an empty string
     */
    public function __construct(
        public DrainOutcome $outcome,
        public int $delivered,
        public int $rejected,
        public string $lastRejection = '',
        public string $failure = '',
    ) {}
}
