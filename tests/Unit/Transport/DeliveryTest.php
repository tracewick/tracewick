<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;

#[CoversClass(Delivery::class)]
final class DeliveryTest extends TestCase
{
    public function testDelivered(): void
    {
        $delivery = Delivery::delivered();

        self::assertSame(DeliveryStatus::Delivered, $delivery->status);
        self::assertSame('', $delivery->reason);
        self::assertTrue($delivery->isDelivered());
    }

    public function testUnsuccessfulOutcomesKeepTheirReason(): void
    {
        foreach ([
            [Delivery::rejected('bad'), DeliveryStatus::Rejected],
            [Delivery::failed('down'), DeliveryStatus::Failed],
            [Delivery::skipped('open'), DeliveryStatus::Skipped],
        ] as [$delivery, $status]) {
            self::assertSame($status, $delivery->status);
            self::assertNotSame('', $delivery->reason);
            self::assertFalse($delivery->isDelivered());
        }

        self::assertSame('bad', Delivery::rejected('bad')->reason);
        self::assertSame('down', Delivery::failed('down')->reason);
        self::assertSame('open', Delivery::skipped('open')->reason);
    }
}
