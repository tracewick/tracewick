<?php

declare(strict_types=1);

namespace Tracewick\Transport;

use Tracewick\Exception\TracewickException;

/**
 * Internal signal of a failed delivery step; transports turn it into a {@see Delivery}.
 *
 * @internal
 */
final class TransportException extends \RuntimeException implements TracewickException {}
