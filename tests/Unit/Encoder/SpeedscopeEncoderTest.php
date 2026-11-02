<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Encoder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\SpeedscopeEncoder;
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

#[CoversClass(SpeedscopeEncoder::class)]
#[UsesClass(Profile::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
final class SpeedscopeEncoderTest extends TestCase
{
    public function testWebRequest(): void
    {
        GoldenFile::assertMatches(
            __DIR__ . '/golden/speedscope-web-request.json',
            (new SpeedscopeEncoder())->encode(ProfileFixtures::webRequest()),
        );
    }

    public function testEdgeCases(): void
    {
        GoldenFile::assertMatches(
            __DIR__ . '/golden/speedscope-edge-cases.json',
            (new SpeedscopeEncoder())->encode(ProfileFixtures::edgeCases()),
        );
    }

    public function testEmptyProfile(): void
    {
        $profile = new Profile(EventType::Cpu, ProfileFixtures::PERIOD_NS, 0, 100);

        self::assertSame(
            '{"$schema":"https://www.speedscope.app/file-format-schema.json","shared":{"frames":[]},'
            . '"profiles":[{"type":"sampled","name":"cpu","unit":"nanoseconds","startValue":0,"endValue":0,'
            . '"samples":[],"weights":[]}],"name":"cpu","activeProfileIndex":0,"exporter":"tracewick"}',
            (new SpeedscopeEncoder())->encode($profile),
        );
    }

    public function testMergesFramesThatDifferOnlyByLine(): void
    {
        $profile = new Profile(EventType::Wall, 10, 0, 100, [
            new Frame('work', null, '/app/w.php', 1),
            new Frame('work', null, '/app/w.php', 2),
        ], [new Stack([0], 1), new Stack([1], 2)]);

        $data = $this->decode((new SpeedscopeEncoder())->encode($profile));

        self::assertSame(['frames' => [['name' => 'work', 'file' => '/app/w.php']]], $data['shared']);
        self::assertSame([[0], [0]], $data['profiles'][0]['samples']);
        self::assertSame([10, 20], $data['profiles'][0]['weights']);
        self::assertSame(30, $data['profiles'][0]['endValue']);
    }

    public function testKeepsFramesOfDifferentFilesApart(): void
    {
        $profile = new Profile(EventType::Wall, 10, 0, 100, [
            new Frame('work', null, '/app/a.php', 1),
            new Frame('work', null, '/app/b.php', 1),
            new Frame('a', null, 'b.php', 1),
            new Frame('ab', null, '.php', 1),
        ], [new Stack([0, 1, 2, 3], 1)]);

        $data = $this->decode((new SpeedscopeEncoder())->encode($profile));

        self::assertSame([
            ['name' => 'work', 'file' => '/app/a.php'],
            ['name' => 'work', 'file' => '/app/b.php'],
            ['name' => 'a', 'file' => 'b.php'],
            ['name' => 'ab', 'file' => '.php'],
        ], $data['shared']['frames']);
        self::assertSame([[0, 1, 2, 3]], $data['profiles'][0]['samples']);
    }

    public function testReplacesInvalidUtf8(): void
    {
        $profile = new Profile(EventType::Wall, 10, 0, 100, [new Frame("bad\xFFname")], [new Stack([0], 1)]);

        $data = $this->decode((new SpeedscopeEncoder())->encode($profile));

        self::assertSame([['name' => "bad\u{FFFD}name"]], $data['shared']['frames']);
    }

    public function testMediaType(): void
    {
        self::assertSame('application/json', (new SpeedscopeEncoder())->mediaType());
    }

    /**
     * @return array{shared: array{frames: list<array<string, string>>}, profiles: array{array{samples: list<list<int>>, weights: list<int>, endValue: int}}}
     */
    private function decode(string $json): array
    {
        /** @var array{shared: array{frames: list<array<string, string>>}, profiles: array{array{samples: list<list<int>>, weights: list<int>, endValue: int}}} */
        return json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
    }
}
