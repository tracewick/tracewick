<?php

declare(strict_types=1);

namespace Tracewick\Transport;

/**
 * Outcome of one attempt to deliver a payload.
 */
enum DeliveryStatus: string
{
    /** The destination accepted the payload. */
    case Delivered = 'delivered';

    /** The destination refused the payload itself; sending it again would not help. */
    case Rejected = 'rejected';

    /** The destination could not be reached or failed; a later attempt may succeed. */
    case Failed = 'failed';

    /** Nothing was sent, for example because the circuit breaker is open. */
    case Skipped = 'skipped';
}
