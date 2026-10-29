<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Transport\SpoolConfig;

#[CoversClass(SpoolConfig::class)]
final class SpoolConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = new SpoolConfig('/var/spool/tracewick');

        self::assertSame('/var/spool/tracewick', $config->directory);
        self::assertSame(1_000, $config->maxFiles);
        self::assertSame(4_194_304, $config->maxFileBytes);
        self::assertSame(67_108_864, $config->minFreeBytes);
    }

    public function testAcceptsTheBounds(): void
    {
        $lower = new SpoolConfig('spool', 1, 1_024, 0);
        $upper = new SpoolConfig('spool', 1_000_000, 268_435_456, \PHP_INT_MAX);

        self::assertSame([1, 1_024, 0], [$lower->maxFiles, $lower->maxFileBytes, $lower->minFreeBytes]);
        self::assertSame([1_000_000, 268_435_456, \PHP_INT_MAX], [$upper->maxFiles, $upper->maxFileBytes, $upper->minFreeBytes]);
    }

    /**
     * @return iterable<string, array{string, int, int, int}>
     */
    public static function invalid(): iterable
    {
        yield 'empty directory' => ['', 1_000, 4_096, 0];
        yield 'directory with a NUL byte' => ["/var/spool\0x", 1_000, 4_096, 0];
        yield 'no files' => ['spool', 0, 4_096, 0];
        yield 'too many files' => ['spool', 1_000_001, 4_096, 0];
        yield 'files too small' => ['spool', 1_000, 1_023, 0];
        yield 'files too large' => ['spool', 1_000, 268_435_457, 0];
        yield 'negative free space' => ['spool', 1_000, 4_096, -1];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidValues(string $directory, int $maxFiles, int $maxFileBytes, int $minFreeBytes): void
    {
        $this->expectException(LogicException::class);

        new SpoolConfig($directory, $maxFiles, $maxFileBytes, $minFreeBytes);
    }
}
