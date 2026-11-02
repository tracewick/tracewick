<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Profile\LabelPiiHeuristics;
use Tracewick\Profile\Labels;

#[CoversClass(LabelPiiHeuristics::class)]
#[UsesClass(Labels::class)]
final class LabelPiiHeuristicsTest extends TestCase
{
    public function testDetectsEmailUuidAndLongDigits(): void
    {
        self::assertSame(['email'], LabelPiiHeuristics::reasons('user@example.com'));
        self::assertSame(['uuid'], LabelPiiHeuristics::reasons('550e8400-e29b-41d4-a716-446655440000'));
        self::assertSame(['long_digits'], LabelPiiHeuristics::reasons('card-1234567890123456'));
        self::assertSame([], LabelPiiHeuristics::reasons('checkout'));
    }

    public function testListsSuspiciousKeys(): void
    {
        $labels = new Labels([
            'route' => '/checkout',
            'email' => 'a@b.co',
            'id' => '550e8400-e29b-41d4-a716-446655440000',
        ]);

        self::assertSame(['email', 'id'], LabelPiiHeuristics::suspiciousKeys($labels));
    }
}
