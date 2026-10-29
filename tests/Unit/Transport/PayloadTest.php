<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Transport\Payload;

#[CoversClass(Payload::class)]
final class PayloadTest extends TestCase
{
    public function testHoldsTheBodyAndItsDescription(): void
    {
        $payload = new Payload("bin\0ary", 'application/x-protobuf', ['name' => 'blog']);

        self::assertSame("bin\0ary", $payload->body);
        self::assertSame('application/x-protobuf', $payload->contentType);
        self::assertSame(['name' => 'blog'], $payload->parameters);
    }

    public function testHasNoParametersByDefault(): void
    {
        self::assertSame([], (new Payload('', 'text/plain'))->parameters);
    }
}
