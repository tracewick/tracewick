<?php

declare(strict_types=1);

namespace Tracewick\Runtime;

/**
 * Why a pass of {@see SpoolDrainer} ended.
 */
enum DrainOutcome: string
{
    /** No file was left to claim. */
    case Empty = 'empty';

    /** The pass handled as many files as it was allowed to; more may be waiting. */
    case LimitReached = 'limit_reached';

    /** A delivery failed or was skipped, or the spool could not be read; the caller should wait. */
    case Failed = 'failed';
}
