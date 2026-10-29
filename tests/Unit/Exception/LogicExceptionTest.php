<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Exception\TracewickException;

#[CoversClass(LogicException::class)]
final class LogicExceptionTest extends TestCase
{
    public function testIsCatchableAsLibraryException(): void
    {
        $this->expectException(TracewickException::class);
        $this->expectExceptionMessage('misconfigured');

        throw new LogicException('misconfigured');
    }

    public function testIsCatchableAsSplLogicException(): void
    {
        $this->expectException(\LogicException::class);

        throw new LogicException('misconfigured');
    }
}
