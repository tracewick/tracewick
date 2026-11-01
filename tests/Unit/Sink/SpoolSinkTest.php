<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Clock\SystemClock;
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
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\SpoolAggregationKey;
use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolSink;
use Tracewick\Tests\Support\GoldenFile;
use Tracewick\Tests\Support\ProfileFixtures;
use Tracewick\Tests\Support\ScriptedTransport;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\Delivery;
use Tracewick\Transport\DeliveryStatus;
use Tracewick\Transport\Payload;
use Tracewick\Transport\SpoolConfig;
use Tracewick\Transport\SpoolTransport;

#[CoversClass(SpoolSink::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(SpoolFile::class)]
#[UsesClass(SpoolConfig::class)]
#[UsesClass(SpoolTransport::class)]
#[UsesClass(Delivery::class)]
#[UsesClass(Payload::class)]
#[UsesClass(SystemClock::class)]
#[UsesClass(CompactStacksCodec::class)]
#[UsesClass(ProfileParts::class)]
#[UsesClass(SpoolAggregationKey::class)]
#[UsesClass(Profile::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
final class SpoolSinkTest extends TestCase
{
    private const START_NS = 1_798_000_000_500_000_000;

    public function testWritesTheProfileAsASpoolFile(): void
    {
        $transport = new ScriptedTransport(Delivery::delivered());
        $sink = new SpoolSink('blog', new SpoolConfig('/unused'), $transport);
        $profile = new Profile(EventType::Cpu, 20_000_000, self::START_NS, self::START_NS + 300, [new Frame('work')], [new Stack([0], 3)], new Labels(['http.route' => '/posts/{id}']));

        $delivery = $sink->send($profile);

        self::assertTrue($delivery->isDelivered());
        $payload = $transport->payload();
        self::assertSame('application/vnd.tracewick.spool', $payload->contentType);
        self::assertArrayHasKey(SpoolTransport::PARAM_AGGREGATION_KEY_HASH, $payload->parameters);
        self::assertSame(
            SpoolAggregationKey::hash('blog', ['http.route' => '/posts/{id}'], EventType::Cpu, 20_000_000),
            $payload->parameters[SpoolTransport::PARAM_AGGREGATION_KEY_HASH],
        );
        $file = SpoolFile::fromBytes($payload->body);
        self::assertSame(SpoolFile::VERSION_2, $file->version);
        self::assertSame(SpoolFile::FORMAT_COMPACT_STACKS, $file->format);
        self::assertEquals(
            new SpoolFile('blog', EventType::Cpu, 20_000_000, self::START_NS, self::START_NS + 300, ['http.route' => '/posts/{id}'], SpoolFile::FORMAT_COMPACT_STACKS, (new CompactStacksCodec())->encode($profile), SpoolFile::VERSION_2),
            $file,
        );
    }

    public function testSkipsAnEmptyProfile(): void
    {
        $transport = new ScriptedTransport();
        $sink = new SpoolSink('blog', new SpoolConfig('/unused'), $transport);

        $delivery = $sink->send(new Profile(EventType::Wall, 10_000_000, self::START_NS, self::START_NS + 1_000));

        self::assertSame(DeliveryStatus::Skipped, $delivery->status);
        self::assertSame('The profile has no samples.', $delivery->reason);
        self::assertSame([], $transport->sent);
    }

    public function testReturnsTheTransportResult(): void
    {
        $transport = new ScriptedTransport(Delivery::skipped('The spool directory already holds 10 files.'));
        $sink = new SpoolSink('blog', new SpoolConfig('/unused'), $transport);

        $delivery = $sink->send(ProfileFixtures::webRequest());

        self::assertSame(DeliveryStatus::Skipped, $delivery->status);
        self::assertSame('The spool directory already holds 10 files.', $delivery->reason);
    }

    public function testNeverThrows(): void
    {
        $transport = new ScriptedTransport(new \RuntimeException('boom'));
        $sink = new SpoolSink('blog', new SpoolConfig('/unused'), $transport);

        $delivery = $sink->send(ProfileFixtures::webRequest());

        self::assertSame(DeliveryStatus::Failed, $delivery->status);
        self::assertSame('Cannot write the profile to the spool: boom', $delivery->reason);
    }

    public function testRejectsAnInvalidApplicationName(): void
    {
        $this->expectException(LogicException::class);

        new SpoolSink('my app', new SpoolConfig('/unused'));
    }

    /**
     * @return iterable<string, array{string, \Closure(): Profile}>
     */
    public static function fixtures(): iterable
    {
        yield 'web request' => ['web-request.spool', ProfileFixtures::webRequest(...)];
        yield 'edge cases' => ['edge-cases.spool', ProfileFixtures::edgeCases(...)];
    }

    /**
     * The valid v2 fixtures shared with other readers are what the sink writes by default.
     *
     * @param \Closure(): Profile $profile
     */
    #[DataProvider('fixtures')]
    public function testWritesTheSharedFixturesToTheDirectory(string $fixture, \Closure $profile): void
    {
        $spool = new TemporaryDirectory();

        try {
            $sink = new SpoolSink('blog', new SpoolConfig($spool->path, 10, 1_024 * 1_024, 0));

            self::assertTrue($sink->send($profile())->isDelivered());
            $shards = $spool->entries();
            self::assertCount(1, $shards);
            $entries = $spool->entries('/' . $shards[0]);
            self::assertCount(1, $entries);
            GoldenFile::assertMatches(
                __DIR__ . '/../../Fixtures/spool/v2/' . $fixture,
                (string) file_get_contents($spool->path . '/' . $shards[0] . '/' . $entries[0]),
            );
        } finally {
            $spool->remove();
        }
    }
}
