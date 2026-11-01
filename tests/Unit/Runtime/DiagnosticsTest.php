<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Runtime\Diagnostics;
use Tracewick\Sampler\NullSamplerReason;

#[CoversClass(Diagnostics::class)]
final class DiagnosticsTest extends TestCase
{
    public function testStartsAtZero(): void
    {
        $diagnostics = new Diagnostics();

        self::assertSame(
            [0, 0, 0, 0, 0, 0, 0, null, 0],
            [
                $diagnostics->collectedProfiles,
                $diagnostics->droppedProfiles,
                $diagnostics->deliveredProfiles,
                $diagnostics->rejectedProfiles,
                $diagnostics->failedProfiles,
                $diagnostics->skippedProfiles,
                $diagnostics->swallowedErrors,
                $diagnostics->inactiveSampler,
                $diagnostics->samplerFailures,
            ],
        );
    }

    public function testKeepsTheCounts(): void
    {
        $diagnostics = new Diagnostics(7, 1, 3, 1, 1, 1, 2, NullSamplerReason::ExtensionMissing, 4);

        self::assertSame(
            [7, 1, 3, 1, 1, 1, 2, NullSamplerReason::ExtensionMissing, 4],
            [
                $diagnostics->collectedProfiles,
                $diagnostics->droppedProfiles,
                $diagnostics->deliveredProfiles,
                $diagnostics->rejectedProfiles,
                $diagnostics->failedProfiles,
                $diagnostics->skippedProfiles,
                $diagnostics->swallowedErrors,
                $diagnostics->inactiveSampler,
                $diagnostics->samplerFailures,
            ],
        );
    }
}
