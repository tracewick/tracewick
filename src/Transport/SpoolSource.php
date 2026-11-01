<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * The reading side of a spool: hands out spool files one at a time to readers that may run in
 * parallel, each file to exactly one reader.
 *
 * A reader claims a file, tries to deliver it and then finishes the claim in one of three ways.
 * Implementations store spool files wherever their writer puts them, such as a directory
 * ({@see DirectorySpoolSource}) or a store added by the application; the bytes are always a
 * spool file as specified in docs/spool-format.md.
 *
 * Implementations never throw. A failure to claim looks like an empty spool, and a claim that
 * cannot be finished is returned to the spool once it is considered abandoned.
 */
interface SpoolSource
{
    /**
     * Claims the oldest available file, or returns null when there is none.
     *
     * @phpstan-impure
     */
    public function claim(): ?ClaimedSpoolFile;

    /**
     * The file was delivered: removes it from the spool.
     *
     * @return bool false when the file could not be removed (caller should count an ack failure)
     */
    public function acknowledge(ClaimedSpoolFile $file): bool;

    /**
     * Delivery failed for a reason that may pass: returns the file to the spool for a later try.
     */
    public function release(ClaimedSpoolFile $file): void;

    /**
     * The file is invalid or the backend refused it: moves it aside so it is not tried again.
     */
    public function reject(ClaimedSpoolFile $file): void;
}
