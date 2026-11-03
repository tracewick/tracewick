<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\Pprof\ProtobufWriter;
use Tracewick\Sampler\EventType;
use Tracewick\Sink\PyroscopePushRequest;
use Tracewick\Tests\Support\ProtobufMessage;

#[CoversClass(PyroscopePushRequest::class)]
#[UsesClass(ProtobufWriter::class)]
final class PyroscopePushRequestTest extends TestCase
{
    public function testBuildsAPushRequestWithSeriesLabelsAndRawProfile(): void
    {
        $push = new PyroscopePushRequest();
        $body = $push->encode('blog', EventType::Wall, [
            'http.route' => '/posts/{id}',
            'span_id' => '00f067aa0ba902b7',
            'region' => 'eu',
        ], "pprof\0bytes", '734fd599-6865-419e-9475-932762d8f469');

        [$labels, $rawProfile, $id] = $this->decode($body);

        self::assertSame([
            '__name__' => 'wall',
            'service_name' => 'blog',
            'pyroscope_spy' => 'tracewick',
            'http_route' => '/posts/{id}',
            'region' => 'eu',
        ], $labels);
        self::assertArrayNotHasKey('span_id', $labels);
        self::assertSame("pprof\0bytes", $rawProfile);
        self::assertSame('734fd599-6865-419e-9475-932762d8f469', $id);
    }

    public function testMapsCpuToProcessCpu(): void
    {
        $push = new PyroscopePushRequest();

        self::assertSame('process_cpu', $push->seriesName(EventType::Cpu));
        self::assertSame('wall', $push->seriesName(EventType::Wall));

        [$labels] = $this->decode($push->encode('app', EventType::Cpu, [], 'x', 'id'));
        self::assertArrayHasKey('__name__', $labels);
        self::assertSame('process_cpu', $labels['__name__']);
    }

    public function testNewSampleIdIsUuidV4(): void
    {
        $id = (new PyroscopePushRequest())->newSampleId();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
        );
    }

    /**
     * @return array{array<string, string>, string, string}
     */
    private function decode(string $body): array
    {
        $series = ProtobufMessage::decode($body)->messages(1)[0] ?? self::fail('No series.');
        $labels = [];
        foreach ($series->messages(1) as $label) {
            $labels[$label->string(1)] = $label->string(2);
        }

        $sample = $series->messages(2)[0] ?? self::fail('No sample.');

        return [$labels, $sample->string(1), $sample->string(2)];
    }
}
