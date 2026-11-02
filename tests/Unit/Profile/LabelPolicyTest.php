<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\LabelPolicy;
use Tracewick\Profile\Labels;

#[CoversClass(LabelPolicy::class)]
#[UsesClass(Labels::class)]
#[UsesClass(LogicException::class)]
final class LabelPolicyTest extends TestCase
{
    #[TestWith(['url'])]
    #[TestWith(['URI'])]
    #[TestWith(['path'])]
    #[TestWith(['user_id'])]
    #[TestWith(['email'])]
    #[TestWith(['span_id'])]
    #[TestWith(['trace_id'])]
    #[TestWith(['request_id'])]
    public function testDeniesSensitiveKeys(string $key): void
    {
        self::assertFalse(LabelPolicy::allowsKey($key));
        self::assertFalse(LabelPolicy::admits($key, 'ok'));
    }

    public function testAllowsOrdinaryKeys(): void
    {
        self::assertTrue(LabelPolicy::allowsKey('route'));
        self::assertTrue(LabelPolicy::allowsKey('env'));
        self::assertTrue(LabelPolicy::admits('route', 'blog_index'));
    }

    #[TestWith(['user@example.com'])]
    #[TestWith(['550e8400-e29b-41d4-a716-446655440000'])]
    #[TestWith(['token?x=1'])]
    #[TestWith(['card-1234567890123456'])]
    public function testDeniesUnsafeValues(string $value): void
    {
        self::assertFalse(LabelPolicy::allowsValue($value));
        self::assertFalse(LabelPolicy::admits('route', $value));
    }

    public function testFilterDropsDeniedPairsAndKeepsSafeOnes(): void
    {
        $filtered = LabelPolicy::filter(new Labels([
            'route' => 'blog_index',
            'email' => 'a@b.co',
            'tenant' => 'acme',
            'id' => '550e8400-e29b-41d4-a716-446655440000',
        ]));

        self::assertSame(['route' => 'blog_index', 'tenant' => 'acme'], $filtered->toArray());
    }
}
