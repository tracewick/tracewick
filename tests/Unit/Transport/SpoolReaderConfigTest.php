<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Transport\SpoolReaderConfig;

#[CoversClass(SpoolReaderConfig::class)]
final class SpoolReaderConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new SpoolReaderConfig('/var/spool/tracewick');

        self::assertSame('/var/spool/tracewick', $config->directory);
        self::assertSame(600_000, $config->staleClaimMs);
        self::assertSame(3_600_000, $config->staleTemporaryMs);
        self::assertSame(100, $config->maxRejectedFiles);
    }

    public function testDerivesTheReaderIdFromTheHostAndTheProcess(): void
    {
        $readerId = (new SpoolReaderConfig('spool'))->readerId;

        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]{1,64}$/D', $readerId);
        self::assertStringEndsWith('-' . getmypid(), $readerId);
    }

    public function testKeepsAGivenReaderId(): void
    {
        self::assertSame('agent-1.eu_west', (new SpoolReaderConfig('spool', 'agent-1.eu_west'))->readerId);
    }

    public function testAcceptsTheBounds(): void
    {
        $lower = new SpoolReaderConfig('spool', 'a', 1_000, 1_000, 0);
        $upper = new SpoolReaderConfig('spool', str_repeat('a', 64), 604_800_000, 604_800_000, 100_000);

        self::assertSame([1_000, 1_000, 0], [$lower->staleClaimMs, $lower->staleTemporaryMs, $lower->maxRejectedFiles]);
        self::assertSame([604_800_000, 604_800_000, 100_000], [$upper->staleClaimMs, $upper->staleTemporaryMs, $upper->maxRejectedFiles]);
    }

    /**
     * @return iterable<string, array{string, ?string, int, int, int, string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty directory' => ['', 'a', 600_000, 3_600_000, 100, 'The spool directory must be a non-empty path.'];
        yield 'directory with a NUL byte' => ["spool\0", 'a', 600_000, 3_600_000, 100, 'The spool directory must be a non-empty path.'];
        yield 'empty reader id' => ['spool', '', 600_000, 3_600_000, 100, 'The reader id must be'];
        yield 'reader id with a slash' => ['spool', 'a/b', 600_000, 3_600_000, 100, 'The reader id must be'];
        yield 'reader id too long' => ['spool', str_repeat('a', 65), 600_000, 3_600_000, 100, 'The reader id must be'];
        yield 'stale claim too short' => ['spool', 'a', 999, 3_600_000, 100, 'The stale claim age'];
        yield 'stale claim too long' => ['spool', 'a', 604_800_001, 3_600_000, 100, 'The stale claim age'];
        yield 'stale temporary too short' => ['spool', 'a', 600_000, 999, 100, 'The stale temporary file age'];
        yield 'stale temporary too long' => ['spool', 'a', 600_000, 604_800_001, 100, 'The stale temporary file age'];
        yield 'negative rejected files' => ['spool', 'a', 600_000, 3_600_000, -1, 'The number of rejected files'];
        yield 'too many rejected files' => ['spool', 'a', 600_000, 3_600_000, 100_001, 'The number of rejected files'];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidValues(string $directory, ?string $readerId, int $staleClaimMs, int $staleTemporaryMs, int $maxRejectedFiles, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        new SpoolReaderConfig($directory, $readerId, $staleClaimMs, $staleTemporaryMs, $maxRejectedFiles);
    }
}
