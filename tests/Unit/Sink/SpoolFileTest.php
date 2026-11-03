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

#[CoversClass(SpoolFile::class)]
#[UsesClass(SpoolFileException::class)]
final class SpoolFileTest extends TestCase
{
    public function testWritesThePreambleTheHeaderAndThePayload(): void
    {
        $file = new SpoolFile('blog', EventType::Wall, 10_000_000, 1_798_000_000_000_000_000, 1_798_000_000_200_000_000, ['env' => 'prod', 'http.route' => '/posts/{slug}'], SpoolFile::FORMAT_COMPACT_STACKS, "\x01\x00");

        $header = '{"application":"blog","event_type":"wall","period_ns":10000000,"start_ns":1798000000000000000,'
            . '"end_ns":1798000000200000000,"labels":{"env":"prod","http.route":"/posts/{slug}"},"format":"compact-stacks","payload_size":2}';
        self::assertSame("TWSP\x02" . pack('N', \strlen($header)) . $header . "\x01\x00", $file->toBytes());
    }

    public function testWritesEmptyLabelsAsAnObject(): void
    {
        $file = new SpoolFile('blog', EventType::Cpu, 1, 0, 0, [], SpoolFile::FORMAT_COMPACT_STACKS, '');

        self::assertStringContainsString('"labels":{}', $file->toBytes());
    }

    public function testReplacesInvalidUtf8InTheHeader(): void
    {
        $file = new SpoolFile('blog', EventType::Cpu, 1, 0, 0, ['path' => "/caf\xe9"], SpoolFile::FORMAT_COMPACT_STACKS, '');

        self::assertSame(['path' => "/caf\u{FFFD}"], SpoolFile::fromBytes($file->toBytes())->labels);
    }

    public function testReadsWhatItWrites(): void
    {
        $file = new SpoolFile('blog', EventType::Cpu, 20_000_000, 5, 7, ['region' => 'eu', '_id' => '1'], SpoolFile::FORMAT_COMPACT_STACKS, "\x00\x01\x02");

        self::assertEquals($file, SpoolFile::fromBytes($file->toBytes()));
        self::assertSame(SpoolFile::VERSION_2, \ord($file->toBytes()[4]));
    }

    public function testIgnoresUnknownHeaderKeys(): void
    {
        $file = SpoolFile::fromBytes(self::file(['future' => ['nested' => [1, 2, 3]]]));

        self::assertSame('blog', $file->application);
        self::assertSame("\x0a\x00", $file->payload);
    }

    public function testRejectsUnsupportedFormatsOnConstruction(): void
    {
        $this->expectException(SpoolFileException::class);
        $this->expectExceptionMessage('Payload format "pprof" is not supported.');

        new SpoolFile('blog', EventType::Wall, 1, 0, 0, [], 'pprof', '');
    }

    public function testRejectsVersion1OnConstruction(): void
    {
        $this->expectException(SpoolFileException::class);
        $this->expectExceptionMessage('Spool format version 1 is not supported.');

        new SpoolFile('blog', EventType::Wall, 1, 0, 0, [], SpoolFile::FORMAT_COMPACT_STACKS, '', 1);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => ['', 'The file does not start with the spool magic bytes.'];
        yield 'too short' => ["TWSP\x02\x00\x00\x00", 'The file does not start with the spool magic bytes.'];
        yield 'wrong magic' => ['XWSP' . substr(self::file(), 4), 'The file does not start with the spool magic bytes.'];
        yield 'version 0' => ["TWSP\x00" . substr(self::file(), 5), 'Spool format version 0 is not supported.'];
        yield 'version 1' => ["TWSP\x01" . substr(self::file(), 5), 'Spool format version 1 is not supported.'];
        yield 'version 3' => ["TWSP\x03" . substr(self::file(), 5), 'Spool format version 3 is not supported.'];
        yield 'empty header' => ["TWSP\x02\x00\x00\x00\x00", 'The header is not valid JSON: Syntax error'];
        yield 'header of exactly the limit, cut short' => ["TWSP\x02" . pack('N', 1_048_576) . '{}', 'The file ends inside the header.'];
        yield 'header too large' => ["TWSP\x02" . pack('N', 1_048_577) . '{}', 'The header of 1048577 bytes exceeds the limit of 1048576 bytes.'];
        yield 'file ends in the header' => ["TWSP\x02" . pack('N', 10) . '{}', 'The file ends inside the header.'];
        yield 'header not JSON' => [self::raw('{"application":'), 'The header is not valid JSON: Syntax error'];
        yield 'header is a list' => [self::raw('[1]'), 'The header is not a JSON object.'];
        yield 'header is a string' => [self::raw('"blog"'), 'The header is not a JSON object.'];
        yield 'payload shorter' => [self::file(['payload_size' => 3]), 'The payload has 2 bytes, the header announces 3.'];
        yield 'payload longer' => [self::file() . 'x', 'The payload has 3 bytes, the header announces 2.'];
        yield 'missing application' => [self::file(['application' => null]), 'Header key "application" must be a string.'];
        yield 'numeric application' => [self::file(['application' => 1]), 'Header key "application" must be a string.'];
        yield 'unknown event type' => [self::file(['event_type' => 'alloc']), 'Unknown event type "alloc".'];
        yield 'period zero' => [self::file(['period_ns' => 0]), 'The sampling period must be at least 1 ns.'];
        yield 'period as string' => [self::file(['period_ns' => '10']), 'Header key "period_ns" must be a non-negative 64-bit integer.'];
        yield 'period as float' => [self::file(['period_ns' => 1.5]), 'Header key "period_ns" must be a non-negative 64-bit integer.'];
        yield 'negative start' => [self::file(['start_ns' => -1]), 'Header key "start_ns" must be a non-negative 64-bit integer.'];
        yield 'start beyond 64 bits' => [self::raw(str_replace('"start_ns":5', '"start_ns":9223372036854775808', self::header())), 'Header key "start_ns" must be a non-negative 64-bit integer.'];
        yield 'end before start' => [self::file(['end_ns' => 4]), 'The profile window ends before it starts.'];
        yield 'labels missing' => [self::file(['labels' => null]), 'Header key "labels" must be an object.'];
        yield 'labels as list' => [self::file(['labels' => ['a']]), 'Header key "labels" must be an object.'];
        yield 'numeric label key' => [self::file(['labels' => ['1' => 'a']]), 'Label key "1" must not be a number.'];
        yield 'label value not a string' => [self::file(['labels' => ['a' => 1]]), 'Label "a" must have a string value.'];
        yield 'format missing' => [self::file(['format' => null]), 'Header key "format" must be a string.'];
        yield 'unknown format' => [self::file(['format' => 'pprof']), 'Payload format "pprof" is not supported.'];
        yield 'payload size missing' => [self::file(['payload_size' => null]), 'Header key "payload_size" must be a non-negative 64-bit integer.'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidFiles(string $bytes, string $message): void
    {
        $this->expectException(SpoolFileException::class);
        $this->expectExceptionMessage($message);

        SpoolFile::fromBytes($bytes);
    }

    public function testKeepsTheJsonErrorAsThePreviousException(): void
    {
        try {
            SpoolFile::fromBytes(self::raw('{'));
            self::fail('The header was accepted.');
        } catch (SpoolFileException $e) {
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
            self::assertSame(\JSON_ERROR_SYNTAX, $e->getCode());
        }
    }

    public function testAcceptsAnEmptyLabelList(): void
    {
        // PHP and other languages may write an empty map as [].
        self::assertSame([], SpoolFile::fromBytes(self::file(['labels' => []]))->labels);
    }

    /**
     * A valid file with a two-byte payload, with header keys replaced or, when null, removed.
     *
     * @param array<string, mixed> $overrides
     */
    private static function file(array $overrides = []): string
    {
        return self::raw(self::header($overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function header(array $overrides = []): string
    {
        $header = array_filter($overrides + [
            'application' => 'blog',
            'event_type' => 'wall',
            'period_ns' => 10,
            'start_ns' => 5,
            'end_ns' => 5,
            'labels' => ['env' => 'prod'],
            'format' => 'compact-stacks',
            'payload_size' => 2,
        ], static fn(mixed $value): bool => null !== $value);

        return json_encode($header, \JSON_THROW_ON_ERROR);
    }

    private static function raw(string $header): string
    {
        return "TWSP\x02" . pack('N', \strlen($header)) . $header . "\x0a\x00";
    }
}
