<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Runtime\DrainSettings;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\SpoolReaderConfig;

#[CoversClass(DrainSettings::class)]
#[UsesClass(SpoolReaderConfig::class)]
#[UsesClass(PyroscopeConfig::class)]
#[UsesClass(ApplicationName::class)]
#[UsesClass(HttpConfig::class)]
#[UsesClass(CircuitBreakerConfig::class)]
final class DrainSettingsTest extends TestCase
{
    private const REQUIRED = ['spool-dir' => '/var/spool/tracewick', 'pyroscope-url' => 'http://pyroscope:4040'];

    public function testUsesDefaults(): void
    {
        $settings = DrainSettings::fromOptions(self::REQUIRED, []);

        self::assertSame('/var/spool/tracewick', $settings->reader->directory);
        self::assertSame(SpoolReaderConfig::readerIdFor((string) gethostname(), (int) getmypid()), $settings->reader->readerId);
        self::assertSame('http://pyroscope:4040', $settings->pyroscope->serverUrl);
        self::assertNull($settings->pyroscope->basicAuthUser);
        self::assertNull($settings->pyroscope->basicAuthPassword);
        self::assertNull($settings->pyroscope->tenantId);
        self::assertFalse($settings->once);
        self::assertNull($settings->limit);
        self::assertNull($settings->memoryLimit);
        self::assertNull($settings->timeLimitSeconds);
        self::assertSame(DrainSettings::DEFAULT_SLEEP_SECONDS, $settings->sleepSeconds);
    }

    public function testReadsEveryOption(): void
    {
        $settings = DrainSettings::fromOptions(self::REQUIRED + [
            'pyroscope-user' => 'drain',
            'pyroscope-tenant' => 'team-a',
            'reader-id' => 'drain-1',
            'once' => '',
            'limit' => '500',
            'memory-limit' => '128M',
            'time-limit' => '3600',
            'sleep' => '0',
        ], ['TRACEWICK_PYROSCOPE_PASSWORD' => 'secret']);

        self::assertSame('drain-1', $settings->reader->readerId);
        self::assertSame('drain', $settings->pyroscope->basicAuthUser);
        self::assertSame('secret', $settings->pyroscope->basicAuthPassword);
        self::assertSame('team-a', $settings->pyroscope->tenantId);
        self::assertTrue($settings->once);
        self::assertSame(500, $settings->limit);
        self::assertSame(128 * 1_024 * 1_024, $settings->memoryLimit);
        self::assertSame(3_600, $settings->timeLimitSeconds);
        self::assertSame(0, $settings->sleepSeconds);
    }

    public function testAcceptsTheBoundaries(): void
    {
        $settings = DrainSettings::fromOptions(self::REQUIRED + ['limit' => '1', 'time-limit' => '1', 'sleep' => '3600'], []);

        self::assertSame(1, $settings->limit);
        self::assertSame(1, $settings->timeLimitSeconds);
        self::assertSame(3_600, $settings->sleepSeconds);
    }

    public function testPrefersOptionsToTheEnvironment(): void
    {
        $settings = DrainSettings::fromOptions(self::REQUIRED + ['pyroscope-tenant' => 'option'], [
            'TRACEWICK_SPOOL_DIR' => '/env/spool',
            'TRACEWICK_PYROSCOPE_URL' => 'http://env:4040',
            'TRACEWICK_PYROSCOPE_TENANT' => 'env',
        ]);

        self::assertSame('/var/spool/tracewick', $settings->reader->directory);
        self::assertSame('http://pyroscope:4040', $settings->pyroscope->serverUrl);
        self::assertSame('option', $settings->pyroscope->tenantId);
    }

    public function testIgnoresEmptyValues(): void
    {
        $settings = DrainSettings::fromOptions(['spool-dir' => '', 'pyroscope-url' => '', 'reader-id' => '', 'limit' => ''], [
            'TRACEWICK_SPOOL_DIR' => '/env/spool',
            'TRACEWICK_PYROSCOPE_URL' => 'http://env:4040',
            'TRACEWICK_PYROSCOPE_USER' => '',
            'TRACEWICK_PYROSCOPE_PASSWORD' => '',
        ]);

        self::assertSame('/env/spool', $settings->reader->directory);
        self::assertSame('http://env:4040', $settings->pyroscope->serverUrl);
        self::assertNull($settings->pyroscope->basicAuthUser);
        self::assertNull($settings->limit);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function memorySizes(): iterable
    {
        yield 'one byte' => ['1', 1];
        yield 'bytes' => ['7', 7];
        yield 'kibibytes' => ['512K', 512 * 1_024];
        yield 'mebibytes in lower case' => ['128m', 128 * 1_024 * 1_024];
        yield 'gibibytes' => ['2G', 2 * 1_024 * 1_024 * 1_024];
        yield 'largest' => ['8589934591G', 8_589_934_591 * 1_024 * 1_024 * 1_024];
    }

    #[DataProvider('memorySizes')]
    public function testReadsMemorySizes(string $value, int $bytes): void
    {
        self::assertSame($bytes, DrainSettings::fromOptions(self::REQUIRED + ['memory-limit' => $value], [])->memoryLimit);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'zero limit' => [['limit' => '0'], '--limit must be a whole number from 1 to ' . \PHP_INT_MAX . ', got "0".'];
        yield 'fractional limit' => [['limit' => '1.5'], '--limit must be a whole number from 1 to ' . \PHP_INT_MAX . ', got "1.5".'];
        yield 'zero time limit' => [['time-limit' => '0'], '--time-limit must be a whole number from 1 to ' . \PHP_INT_MAX . ', got "0".'];
        yield 'negative sleep' => [['sleep' => '-1'], '--sleep must be a whole number from 0 to 3600, got "-1".'];
        yield 'too long sleep' => [['sleep' => '3601'], '--sleep must be a whole number from 0 to 3600, got "3601".'];
        yield 'zero memory' => [['memory-limit' => '0'], '--memory-limit must be a size such as 128M, got "0".'];
        yield 'unknown unit' => [['memory-limit' => '12T'], '--memory-limit must be a size such as 128M, got "12T".'];
        yield 'too large memory' => [['memory-limit' => '8589934592G'], '--memory-limit must be a size such as 128M, got "8589934592G".'];
        yield 'memory with a prefix' => [['memory-limit' => 'x128M'], '--memory-limit must be a size such as 128M, got "x128M".'];
        yield 'unit only' => [['memory-limit' => 'M'], '--memory-limit must be a size such as 128M, got "M".'];
        yield 'leading zero' => [['limit' => '07'], '--limit must be a whole number from 1 to ' . \PHP_INT_MAX . ', got "07".'];
        yield 'overflowing limit' => [['limit' => '99999999999999999999'], '--limit must be a whole number from 1 to ' . \PHP_INT_MAX . ', got "99999999999999999999".'];
        yield 'invalid reader id' => [['reader-id' => 'a/b'], 'The reader id must be'];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('invalidOptions')]
    public function testRejectsInvalidOptions(array $options, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        DrainSettings::fromOptions($options + self::REQUIRED, []);
    }

    public function testRequiresThePyroscopeUrl(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The Pyroscope URL is required.');

        DrainSettings::fromOptions(['spool-dir' => '/var/spool/tracewick'], []);
    }

    public function testRequiresAUserForThePassword(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Basic authentication needs both a user and a password.');

        DrainSettings::fromOptions(self::REQUIRED, ['TRACEWICK_PYROSCOPE_PASSWORD' => 'secret']);
    }

    public function testRequiresTheSpoolDirectory(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The spool directory is required.');

        DrainSettings::fromOptions(['pyroscope-url' => 'http://pyroscope:4040'], []);
    }
}
