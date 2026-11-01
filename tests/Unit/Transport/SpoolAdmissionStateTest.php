<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Transport\SpoolAdmissionState;

#[CoversClass(SpoolAdmissionState::class)]
final class SpoolAdmissionStateTest extends TestCase
{
    public function testNeedsRefreshUntilTheFirstScan(): void
    {
        $state = new SpoolAdmissionState();

        self::assertTrue($state->needsRefresh(1_000));
    }

    public function testRefreshResetsTheWriteCounter(): void
    {
        $state = new SpoolAdmissionState();
        $state->refresh(3, 1_000);
        $state->recordWrite();
        $state->recordWrite();

        self::assertSame(5, $state->cachedCount);
        self::assertSame(2, $state->writesSinceScan);

        $state->refresh(4, 2_000);

        self::assertSame(4, $state->cachedCount);
        self::assertSame(0, $state->writesSinceScan);
        self::assertSame(2_000, $state->cachedAtNs);
        self::assertFalse($state->needsRefresh(2_000));
    }

    public function testNeedsRefreshAfterTheTtl(): void
    {
        $state = new SpoolAdmissionState();
        $state->refresh(1, 1_000);

        self::assertFalse($state->needsRefresh(1_000 + SpoolAdmissionState::TTL_NS - 1));
        self::assertTrue($state->needsRefresh(1_000 + SpoolAdmissionState::TTL_NS));
    }

    public function testNeedsRefreshAfterEnoughWrites(): void
    {
        $state = new SpoolAdmissionState();
        $state->refresh(0, 1_000);
        for ($i = 0; $i < SpoolAdmissionState::REFRESH_EVERY_WRITES; ++$i) {
            $state->recordWrite();
        }

        self::assertTrue($state->needsRefresh(1_000));
    }
}
