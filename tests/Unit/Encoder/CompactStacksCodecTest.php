<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Encoder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\CompactStacksCodec;
use Tracewick\Encoder\ProfileParts;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Tests\Support\ProfileFixtures;

#[CoversClass(CompactStacksCodec::class)]
#[UsesClass(ProfileParts::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
#[UsesClass(LogicException::class)]
final class CompactStacksCodecTest extends TestCase
{
    public function testRoundTripsTheWebRequestProfile(): void
    {
        $profile = ProfileFixtures::webRequest();
        $codec = new CompactStacksCodec();

        $parts = $codec->decode($codec->encode($profile));

        self::assertEquals($profile->frames, $parts->frames);
        self::assertEquals($profile->stacks, $parts->stacks);
    }

    public function testRoundTripsNullOptionalFieldsAndTruncation(): void
    {
        $profile = new Profile(
            EventType::Cpu,
            10_000_000,
            1,
            2,
            [new Frame(null, null, null, 0), new Frame('fn', 'Cls', '/a.php', 10, 11)],
            [new Stack([0, 1], 5, true)],
        );
        $codec = new CompactStacksCodec();

        $parts = $codec->decode($codec->encode($profile));

        self::assertEquals($profile->frames, $parts->frames);
        self::assertEquals($profile->stacks, $parts->stacks);
    }

    public function testRejectsAnUnsupportedSchemaVersion(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Compact stacks schema version 9 is not supported.');

        (new CompactStacksCodec())->decode("\x09");
    }

    public function testRejectsTrailingBytes(): void
    {
        $encoded = (new CompactStacksCodec())->encode(ProfileFixtures::edgeCases());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Compact stacks payload has trailing bytes.');

        (new CompactStacksCodec())->decode($encoded . "\x00");
    }
}
