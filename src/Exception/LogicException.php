<?php

declare(strict_types=1);

namespace Tracewick\Exception;

/**
 * Signals a programming error, such as invalid configuration, that should be fixed in code.
 */
final class LogicException extends \LogicException implements TracewickException {}
