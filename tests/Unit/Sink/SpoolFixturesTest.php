<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Sampler\EventType;
use Tracewick\Sink\SpoolFile;
use Tracewick\Sink\SpoolFileException;
use Tracewick\Tests\Support\GoldenFile;

/**
 * Checks the fixture files in tests/Fixtures/spool/v1, which every spool reader and writer is
 * tested against, including readers in other languages. See the README in that directory.
 *
 * The valid fixtures are written by {@see SpoolSinkTest}; the invalid ones are built here from a
 * valid file, so that `TRACEWICK_UPDATE_GOLDEN=1` regenerates all of them.
 */
#[CoversClass(SpoolFile::class)]
#[UsesClass(SpoolFileException::class)]
final class SpoolFixturesTest extends TestCase
{
    private const DIRECTORY = __DIR__ . '/../../Fixtures/spool/v1/';

    private const START_NS = 1_798_000_000_000_000_000;

    public function testReadsTheWebRequestFixture(): void
    {
        $file = SpoolFile::fromBytes($this->fixture('web-request.spool'));

        self::assertSame('blog', $file->application);
        self::assertSame(EventType::Wall, $file->eventType);
        self::assertSame(10_000_000, $file->periodNs);
        self::assertSame(self::START_NS, $file->startNs);
        self::assertSame(self::START_NS + 200_000_000, $file->endNs);
        self::assertSame(['service' => 'blog'], $file->labels);
        self::assertSame('pprof', $file->format);
        self::assertSame($this->golden('pprof-web-request.pb'), $file->payload);
    }

    public function testReadsTheEdgeCasesFixture(): void
    {
        $file = SpoolFile::fromBytes($this->fixture('edge-cases.spool'));

        self::assertSame(EventType::Cpu, $file->eventType);
        self::assertSame([], $file->labels);
        self::assertSame($this->golden('pprof-edge-cases.pb'), $file->payload);
    }

    public function testReadsTheFixtureWithAnUnknownHeaderKey(): void
    {
        $bytes = self::withHeader($this->fixture('web-request.spool'), static fn(array $header): array => $header + ['x_future' => ['nested' => [1, 2]]]);
        GoldenFile::assertMatches(self::DIRECTORY . 'unknown-header-key.spool', $bytes);

        self::assertSame($this->golden('pprof-web-request.pb'), SpoolFile::fromBytes($bytes)->payload);
    }

    public function testReadsTheFixtureWithAnUnknownFormat(): void
    {
        $bytes = self::withHeader($this->fixture('web-request.spool'), static fn(array $header): array => ['format' => 'x-unknown'] + $header);
        GoldenFile::assertMatches(self::DIRECTORY . 'unknown-format.spool', $bytes);

        // Parsing succeeds; the reader must not deliver a format it does not support.
        self::assertSame('x-unknown', SpoolFile::fromBytes($bytes)->format);
    }

    /**
     * @return iterable<string, array{string, \Closure(string): string}>
     */
    public static function invalid(): iterable
    {
        yield 'bad magic' => ['bad-magic.spool', static fn(string $valid): string => 'XWSP' . substr($valid, 4)];
        yield 'unsupported version' => ['unsupported-version.spool', static fn(string $valid): string => "TWSP\x03" . substr($valid, 5)];
        yield 'header too large' => ['header-too-large.spool', static fn(string $valid): string => "TWSP\x01" . pack('N', SpoolFile::MAX_HEADER_BYTES + 1) . substr($valid, 9)];
        yield 'truncated payload' => ['truncated-payload.spool', static fn(string $valid): string => substr($valid, 0, -1)];
        yield 'header not JSON' => ['header-not-json.spool', static fn(string $valid): string => self::withRawHeader($valid, '{"application":"blog",')];
        yield 'missing key' => ['missing-key.spool', static fn(string $valid): string => self::withHeader($valid, static function (array $header): array {
            unset($header['start_ns']);

            return $header;
        })];
        yield 'end before start' => ['end-before-start.spool', static fn(string $valid): string => self::withHeader($valid, static fn(array $header): array => ['end_ns' => self::START_NS - 1] + $header)];
    }

    /**
     * @param \Closure(string): string $corrupt
     */
    #[DataProvider('invalid')]
    public function testRejectsTheInvalidFixtures(string $fixture, \Closure $corrupt): void
    {
        $bytes = $corrupt($this->fixture('web-request.spool'));
        GoldenFile::assertMatches(self::DIRECTORY . $fixture, $bytes);

        $this->expectException(SpoolFileException::class);

        SpoolFile::fromBytes($bytes);
    }

    private function fixture(string $name): string
    {
        $bytes = file_get_contents(self::DIRECTORY . $name);
        self::assertIsString($bytes, \sprintf('Fixture %s is missing.', $name));

        return $bytes;
    }

    private function golden(string $name): string
    {
        $bytes = file_get_contents(__DIR__ . '/../Encoder/golden/' . $name);
        self::assertIsString($bytes);

        return $bytes;
    }

    /**
     * Rewrites the JSON header of a valid file, keeping its payload.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    private static function withHeader(string $valid, \Closure $change): string
    {
        /** @var array{1: int} $length */
        $length = unpack('N', $valid, 5);
        /** @var array<string, mixed> $header */
        $header = json_decode(substr($valid, 9, $length[1]), true, 512, \JSON_THROW_ON_ERROR);

        return self::withRawHeader($valid, json_encode($change($header), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }

    private static function withRawHeader(string $valid, string $header): string
    {
        /** @var array{1: int} $length */
        $length = unpack('N', $valid, 5);

        return "TWSP\x01" . pack('N', \strlen($header)) . $header . substr($valid, 9 + $length[1]);
    }
}
