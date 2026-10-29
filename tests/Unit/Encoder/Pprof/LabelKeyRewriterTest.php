<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Encoder\Pprof;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\Pprof\LabelKeyRewriter;
use Tracewick\Encoder\Pprof\ProtobufException;
use Tracewick\Encoder\Pprof\ProtobufReader;
use Tracewick\Encoder\Pprof\ProtobufWriter;
use Tracewick\Encoder\Pprof\StringTable;
use Tracewick\Encoder\PprofEncoder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\Frame;
use Tracewick\Tests\Support\ProtobufMessage;

#[CoversClass(LabelKeyRewriter::class)]
#[UsesClass(ProtobufReader::class)]
#[UsesClass(ProtobufWriter::class)]
#[UsesClass(ProtobufException::class)]
#[UsesClass(StringTable::class)]
#[UsesClass(PprofEncoder::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
final class LabelKeyRewriterTest extends TestCase
{
    public function testRenamesLabelKeysOfEverySample(): void
    {
        $pprof = $this->pprof(['http.route' => '/posts/{id}', 'env' => 'prod']);

        $rewritten = LabelKeyRewriter::rewrite($pprof, $this->underscores(...));

        self::assertSame([
            ['env' => 'prod', 'http_route' => '/posts/{id}'],
            ['env' => 'prod', 'http_route' => '/posts/{id}'],
        ], $this->labels($rewritten));
    }

    public function testKeepsFunctionNamesThatEqualALabelKey(): void
    {
        $pprof = $this->pprof(['http.route' => '/'], 'http.route');

        $rewritten = LabelKeyRewriter::rewrite($pprof, $this->underscores(...));

        $message = ProtobufMessage::decode($rewritten);
        $strings = $message->strings(6);
        self::assertSame('http.route', $strings[$message->messages(5)[0]->int(2)] ?? null);
        self::assertSame([['http_route' => '/'], ['http_route' => '/']], $this->labels($rewritten));
    }

    public function testReusesAnExistingStringAndAppendsOnlyNewOnes(): void
    {
        $pprof = $this->pprof(['a.b' => 'a_b', 'c.d' => 'x']);
        $before = ProtobufMessage::decode($pprof)->strings(6);

        $after = ProtobufMessage::decode(LabelKeyRewriter::rewrite($pprof, $this->underscores(...)))->strings(6);

        self::assertSame([...$before, 'c_d'], $after);
    }

    public function testMapsTwoKeysToTheSameNewName(): void
    {
        $pprof = $this->pprof(['a.b' => '1', 'a_c' => '2']);

        $rewritten = LabelKeyRewriter::rewrite($pprof, static fn(string $key): string => 'same');

        self::assertSame([...ProtobufMessage::decode($pprof)->strings(6), 'same'], ProtobufMessage::decode($rewritten)->strings(6));
    }

    public function testReturnsTheInputWhenNothingIsRenamed(): void
    {
        $pprof = $this->pprof(['env' => 'prod']);

        self::assertSame($pprof, LabelKeyRewriter::rewrite($pprof, $this->underscores(...)));
    }

    public function testKeepsEverythingButTheRenamedKeys(): void
    {
        $pprof = $this->pprof(['http.route' => '/']);

        $before = ProtobufReader::fields($pprof);
        $after = ProtobufReader::fields(LabelKeyRewriter::rewrite($pprof, $this->underscores(...)));

        self::assertSame([...$this->otherFields($before), (new ProtobufWriter())->bytes(6, 'http_route')->toString()], $this->otherFields($after));
        self::assertSame($this->sampleLocations($before), $this->sampleLocations($after));
    }

    public function testIgnoresLabelsWithoutAKeyOrWithAnUnknownKey(): void
    {
        $label = (new ProtobufWriter())->varint(2, 1)->toString();
        $unknown = (new ProtobufWriter())->varint(1, 99)->varint(2, 1)->toString();
        $pprof = (new ProtobufWriter())
            ->bytes(2, (new ProtobufWriter())->bytes(3, $label)->bytes(3, $unknown)->toString())
            ->bytes(6, '')
            ->bytes(6, 'value')
            ->toString();

        self::assertSame($pprof, LabelKeyRewriter::rewrite($pprof, static fn(string $key): string => $key . '!'));
    }

    public function testRejectsInvalidProtobuf(): void
    {
        $this->expectException(ProtobufException::class);

        LabelKeyRewriter::rewrite("\x12\x05abc", $this->underscores(...));
    }

    public function testRejectsInvalidSamples(): void
    {
        $this->expectException(ProtobufException::class);

        LabelKeyRewriter::rewrite((new ProtobufWriter())->bytes(2, "\x1a\x05ab")->toString(), $this->underscores(...));
    }

    private function underscores(string $key): string
    {
        return str_replace('.', '_', $key);
    }

    /**
     * @param list<array{field: int, wire: int, value: int|string, raw: string}> $fields
     *
     * @return list<string> encoded fields other than samples
     */
    private function otherFields(array $fields): array
    {
        $other = [];
        foreach ($fields as $field) {
            if (2 !== $field['field']) {
                $other[] = $field['raw'];
            }
        }

        return $other;
    }

    /**
     * @param list<array{field: int, wire: int, value: int|string, raw: string}> $fields
     *
     * @return list<string> packed location ids of each sample
     */
    private function sampleLocations(array $fields): array
    {
        $locations = [];
        foreach ($fields as $field) {
            if (2 === $field['field']) {
                $locations[] = ProtobufMessage::decode((string) $field['value'])->string(1);
            }
        }

        return $locations;
    }

    /**
     * @param array<string, string> $labels
     */
    private function pprof(array $labels, string $function = 'work'): string
    {
        return (new PprofEncoder())->encode(new Profile(
            EventType::Wall,
            10_000_000,
            1_798_000_000_000_000_000,
            1_798_000_000_100_000_000,
            [new Frame($function)],
            [new Stack([0], 3), new Stack([0], 1, true)],
            new Labels($labels),
        ));
    }

    /**
     * @return list<array<string, string>> the labels of each sample, by name
     */
    private function labels(string $pprof): array
    {
        $message = ProtobufMessage::decode($pprof);
        $strings = $message->strings(6);
        $samples = [];
        foreach ($message->messages(2) as $sample) {
            $labels = [];
            foreach ($sample->messages(3) as $label) {
                $labels[$strings[$label->int(1)] ?? '?'] = $strings[$label->int(2)] ?? '?';
            }

            ksort($labels);
            $samples[] = $labels;
        }

        return $samples;
    }
}
