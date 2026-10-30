<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Runtime\Diagnostics;

#[CoversClass(Diagnostics::class)]
final class DiagnosticsTest extends TestCase
{
    public function testStartsAtZero(): void
    {
        $diagnostics = new Diagnostics();

        self::assertSame(
            [0, 0, 0, 0, 0, 0, 0],
            [
                $diagnostics->collectedProfiles,
                $diagnostics->droppedProfiles,
                $diagnostics->deliveredProfiles,
                $diagnostics->rejectedProfiles,
                $diagnostics->failedProfiles,
                $diagnostics->skippedProfiles,
                $diagnostics->swallowedErrors,
            ],
        );
    }

    public function testKeepsTheCounts(): void
    {
        $diagnostics = new Diagnostics(7, 1, 3, 1, 1, 1, 2);

        self::assertSame(
            [7, 1, 3, 1, 1, 1, 2],
            [
                $diagnostics->collectedProfiles,
                $diagnostics->droppedProfiles,
                $diagnostics->deliveredProfiles,
                $diagnostics->rejectedProfiles,
                $diagnostics->failedProfiles,
                $diagnostics->skippedProfiles,
                $diagnostics->swallowedErrors,
            ],
        );
    }
}
