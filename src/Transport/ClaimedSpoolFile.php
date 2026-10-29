<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * A spool file that one reader has claimed and must finish: acknowledge, release or reject it
 * through the {@see SpoolSource} it came from.
 */
final readonly class ClaimedSpoolFile
{
    /**
     * @param string $name   name of the file in the spool, such as `01798000000000000000-0123456789abcdef.spool`
     * @param string $bytes  contents of the file, not yet validated
     * @param string $handle where the source keeps the claimed file; meaningful only to that source
     */
    public function __construct(
        public string $name,
        public string $bytes,
        public string $handle,
    ) {}
}
