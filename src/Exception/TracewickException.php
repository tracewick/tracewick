<?php

declare(strict_types=1);

namespace Tracewick\Exception;

/**
 * Marker interface implemented by every exception thrown by Tracewick.
 *
 * Catching this interface is enough to intercept any library failure.
 */
interface TracewickException extends \Throwable {}
