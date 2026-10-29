<?php

declare(strict_types=1);

namespace Tracewick\Sink;

use Tracewick\Exception\TracewickException;

/**
 * Thrown when bytes are not a spool file that a reader may deliver.
 *
 * Such a file will not get better with time: readers move it aside instead of trying again.
 */
final class SpoolFileException extends \RuntimeException implements TracewickException {}
