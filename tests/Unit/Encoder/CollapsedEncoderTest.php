<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Encoder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\CollapsedEncoder;
use Tracewick\Encoder\CollapsedValue;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Tests\Support\GoldenFile;
use Tracewick\Tests\Support\ProfileFixtures;

#[CoversClass(CollapsedEncoder::class)]
#[UsesClass(Profile::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
final class CollapsedEncoderTest extends TestCase
{
    public function testWebRequest(): void
    {
        GoldenFile::assertMatches(
            __DIR__ . '/golden/collapsed-web-request.txt',
            (new CollapsedEncoder())->encode(ProfileFixtures::webRequest()),
        );
    }

    public function testWebRequestInNanoseconds(): void
    {
        GoldenFile::assertMatches(
            __DIR__ . '/golden/collapsed-web-request-ns.txt',
            (new CollapsedEncoder(CollapsedValue::Nanoseconds))->encode(ProfileFixtures::webRequest()),
        );
    }

    public function testEdgeCases(): void
    {
        GoldenFile::assertMatches(
            __DIR__ . '/golden/collapsed-edge-cases.txt',
            (new CollapsedEncoder())->encode(ProfileFixtures::edgeCases()),
        );
    }

    public function testEmptyProfileGivesEmptyOutput(): void
    {
        $profile = new Profile(EventType::Wall, ProfileFixtures::PERIOD_NS, 0, 100);

        self::assertSame('', (new CollapsedEncoder())->encode($profile));
    }

    public function testOutputDoesNotDependOnStackOrder(): void
    {
        $a = new Frame('a');
        $b = new Frame('b');
        $forward = new Profile(EventType::Wall, ProfileFixtures::PERIOD_NS, 0, 100, [$a, $b], [new Stack([0], 1), new Stack([1], 2)]);
        $backward = new Profile(EventType::Wall, ProfileFixtures::PERIOD_NS, 0, 100, [$b, $a], [new Stack([0], 2), new Stack([1], 1)]);

        $encoder = new CollapsedEncoder();

        self::assertSame("a 1\nb 2\n", $encoder->encode($forward));
        self::assertSame($encoder->encode($forward), $encoder->encode($backward));
    }

    public function testUnknownFrameIdsBecomeUnknownFrames(): void
    {
        $profile = new Profile(EventType::Wall, ProfileFixtures::PERIOD_NS, 0, 100, [new Frame('a')], [new Stack([0, 9], 1)]);

        self::assertSame("a;[unknown] 1\n", (new CollapsedEncoder())->encode($profile));
    }

    public function testMediaType(): void
    {
        self::assertSame('text/plain; charset=utf-8', (new CollapsedEncoder())->mediaType());
    }
}
