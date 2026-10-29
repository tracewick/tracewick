<?php

declare(strict_types=1);

namespace Tracewick\Encoder\Pprof;

use Tracewick\Exception\TracewickException;

/**
 * Thrown when bytes are not valid protocol buffers.
 *
 * @internal
 */
final class ProtobufException extends \RuntimeException implements TracewickException {}
