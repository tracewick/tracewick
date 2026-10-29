<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Encoder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\Pprof\ProtobufWriter;
use Tracewick\Encoder\Pprof\StringTable;
use Tracewick\Encoder\PprofEncoder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;
use Tracewick\Tests\Support\GoldenFile;
use Tracewick\Tests\Support\ProfileFixtures;
use Tracewick\Tests\Support\ProtobufMessage;

/**
 * Field numbers are those of profile.proto. The golden files are also read by `go tool pprof`
 * in CI, see .github/workflows/ci.yml.
 */
#[CoversClass(PprofEncoder::class)]
#[UsesClass(ProtobufWriter::class)]
#[UsesClass(StringTable::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
final class PprofEncoderTest extends TestCase
{
    public function testWebRequest(): void
    {
        GoldenFile::assertMatches(
            __DIR__ . '/golden/pprof-web-request.pb',
            (new PprofEncoder())->encode(ProfileFixtures::webRequest()),
        );
    }

    public function testEdgeCases(): void
    {
        GoldenFile::assertMatches(
            __DIR__ . '/golden/pprof-edge-cases.pb',
            (new PprofEncoder())->encode(ProfileFixtures::edgeCases()),
        );
    }

    public function testEmptyProfile(): void
    {
        $profile = ProtobufMessage::decode((new PprofEncoder())->encode(new Profile(EventType::Cpu, 10, 1_000, 1_500)));

        $strings = $profile->strings(6);
        self::assertSame(['', 'samples', 'count', 'cpu', 'nanoseconds'], $strings);
        self::assertSame(
            [['samples', 'count'], ['cpu', 'nanoseconds']],
            array_map(fn(ProtobufMessage $type): array => $this->valueType($strings, $type), $profile->messages(1)),
        );
        self::assertFalse($profile->has(2));
        self::assertFalse($profile->has(4));
        self::assertFalse($profile->has(5));
        self::assertSame(1_000, $profile->int(9));
        self::assertSame(500, $profile->int(10));
        self::assertSame(['cpu', 'nanoseconds'], $this->valueType($strings, $profile->message(11)));
        self::assertSame(10, $profile->int(12));
    }

    public function testSampleValuesAndLabels(): void
    {
        $profile = ProtobufMessage::decode((new PprofEncoder())->encode(new Profile(
            EventType::Wall,
            10,
            0,
            100,
            [new Frame('work')],
            [new Stack([0], 3), new Stack([], 2)],
            new Labels(['env' => 'prod', 'service' => 'blog']),
        )));
        $strings = $profile->strings(6);
        $samples = $profile->messages(2);

        self::assertCount(2, $samples);
        self::assertSame([3, 30], $this->item($samples, 0)->packed(2));
        self::assertSame([2, 20], $this->item($samples, 1)->packed(2));
        foreach ($samples as $sample) {
            self::assertSame(['env=prod', 'service=blog'], array_map(
                fn(ProtobufMessage $label): string => $this->string($strings, $label->int(1)) . '=' . $this->string($strings, $label->int(2)),
                $sample->messages(3),
            ));
        }
    }

    public function testKeepsLinesAndSharesFunctions(): void
    {
        $profile = ProtobufMessage::decode((new PprofEncoder())->encode(new Profile(EventType::Wall, 10, 0, 100, [
            new Frame('work', null, '/app/w.php', 1),
            new Frame('work', null, '/app/w.php', 2),
            new Frame('work', null, '/app/other.php', 2),
            new Frame('{closure}', null, '/app/w.php', 9, 7),
            new Frame('a', null, 'b.php', 1),
            new Frame('ab', null, '.php', 1),
        ], [new Stack([3, 0, 1, 2, 4, 5], 1)])));
        $strings = $profile->strings(6);

        self::assertSame([
            [1, '{closure:/app/w.php:7}', '{closure:/app/w.php:7}', '/app/w.php', 7],
            [2, 'work', 'work', '/app/w.php', 0],
            [3, 'work', 'work', '/app/other.php', 0],
            [4, 'a', 'a', 'b.php', 0],
            [5, 'ab', 'ab', '.php', 0],
        ], array_map(fn(ProtobufMessage $function): array => [
            $function->int(1),
            $this->string($strings, $function->int(2)),
            $this->string($strings, $function->int(3)),
            $this->string($strings, $function->int(4)),
            $function->int(5),
        ], $profile->messages(5)));

        self::assertSame(
            [[1, 1, 9], [2, 2, 1], [3, 2, 2], [4, 3, 2], [5, 4, 1], [6, 5, 1]],
            array_map(fn(ProtobufMessage $location): array => [
                $location->int(1),
                $location->message(4)->int(1),
                $location->message(4)->int(2),
            ], $profile->messages(4)),
        );

        // Locations are listed leaf first.
        self::assertSame([6, 5, 4, 3, 2, 1], $this->item($profile->messages(2), 0)->packed(1));
    }

    public function testUnknownAndTruncatedFrames(): void
    {
        $profile = ProtobufMessage::decode((new PprofEncoder())->encode(new Profile(EventType::Cpu, 10, 0, 100, [new Frame('work')], [
            new Stack([0, 7], 1, true),
            new Stack([], 1, true),
            new Stack([], 1),
            new Stack([9], 1),
        ])));
        $strings = $profile->strings(6);

        $functionNames = [];
        foreach ($profile->messages(5) as $function) {
            $functionNames[$function->int(1)] = $this->string($strings, $function->int(2));
            // No frame here has a line, so placeholders must not invent one either.
            self::assertSame(0, $function->int(5));
        }

        $locationNames = [];
        foreach ($profile->messages(4) as $location) {
            $locationNames[$location->int(1)] = $functionNames[$location->message(4)->int(1)] ?? self::fail('Unknown function.');
            self::assertSame(0, $location->message(4)->int(2));
        }

        self::assertCount(3, $locationNames);
        self::assertSame([
            ['[unknown]', 'work', '[truncated]'],
            ['[truncated]'],
            ['[unknown]'],
            ['[unknown]'],
        ], array_map(
            fn(ProtobufMessage $sample): array => array_map(
                fn(int $id): string => $locationNames[$id] ?? self::fail('Unknown location.'),
                $sample->packed(1),
            ),
            $profile->messages(2),
        ));
    }

    public function testMediaType(): void
    {
        self::assertSame('application/x-protobuf', (new PprofEncoder())->mediaType());
    }

    /**
     * @param list<string> $strings
     */
    private function string(array $strings, int $index): string
    {
        return $this->item($strings, $index);
    }

    /**
     * @template T
     *
     * @param list<T> $list
     *
     * @return T
     */
    private function item(array $list, int $index): mixed
    {
        return $list[$index] ?? self::fail(\sprintf('No item %d.', $index));
    }

    /**
     * @param list<string> $strings
     *
     * @return array{string, string}
     */
    private function valueType(array $strings, ProtobufMessage $type): array
    {
        return [$this->string($strings, $type->int(1)), $this->string($strings, $type->int(2))];
    }
}
