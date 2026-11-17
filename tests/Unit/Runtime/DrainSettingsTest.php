<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Runtime\DrainSettings;
use Tracewick\Runtime\SpoolAggregationWindow;
use Tracewick\Sink\ApplicationName;
use Tracewick\Sink\PyroscopeConfig;
use Tracewick\Transport\CircuitBreakerConfig;
use Tracewick\Transport\HttpConfig;
use Tracewick\Transport\SpoolReaderConfig;

#[CoversClass(DrainSettings::class)]
#[UsesClass(SpoolReaderConfig::class)]
#[UsesClass(SpoolAggregationWindow::class)]
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

    public function testIgnoresEmptyEnvironmentValues(): void
    {
        $settings = DrainSettings::fromOptions([], [
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

    public function testReadsOperationalEnvironmentValues(): void
    {
        $settings = DrainSettings::fromOptions(self::REQUIRED, [
            'TRACEWICK_READER_ID' => 'env-reader',
            'TRACEWICK_LIMIT' => '7',
            'TRACEWICK_MEMORY_LIMIT' => '128M',
            'TRACEWICK_TIME_LIMIT_SECONDS' => '60',
            'TRACEWICK_SLEEP_SECONDS' => '0',
            'TRACEWICK_AGGREGATION_WINDOW' => '10',
        ]);
        self::assertSame('env-reader', $settings->reader->readerId);
        self::assertSame(7, $settings->limit);
        self::assertSame(128 * 1024 * 1024, $settings->memoryLimit);
        self::assertSame(60, $settings->timeLimitSeconds);
        self::assertSame(0, $settings->sleepSeconds);
        self::assertSame(10_000_000_000, $settings->aggregationWindow->durationNs);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function environmentOptions(): iterable
    {
        yield 'directory' => ['spool-dir', 'TRACEWICK_SPOOL_DIR', '/spool'];
        yield 'URL' => ['pyroscope-url', 'TRACEWICK_PYROSCOPE_URL', 'http://localhost:4040'];
        yield 'reader' => ['reader-id', 'TRACEWICK_READER_ID', 'reader'];
        yield 'limit' => ['limit', 'TRACEWICK_LIMIT', '2'];
        yield 'memory' => ['memory-limit', 'TRACEWICK_MEMORY_LIMIT', '2K'];
        yield 'time' => ['time-limit', 'TRACEWICK_TIME_LIMIT_SECONDS', '2'];
        yield 'sleep' => ['sleep', 'TRACEWICK_SLEEP_SECONDS', '0'];
        yield 'window' => ['aggregation-window', 'TRACEWICK_AGGREGATION_WINDOW', '10'];
    }

    #[DataProvider('environmentOptions')]
    public function testCLIOverridesInvalidEnvironment(string $option, string $variable, string $valid): void
    {
        $options = [$option => $valid] + self::REQUIRED;
        self::assertEquals(
            DrainSettings::fromOptions($options, []),
            DrainSettings::fromOptions($options, [$variable => 'invalid']),
        );
    }

    #[DataProvider('environmentOptions')]
    public function testExplicitEmptyValuesDoNotFallBack(string $option, string $variable, string $valid): void
    {
        if ($option === 'reader-id') {
            $settings = DrainSettings::fromOptions(['reader-id' => ''] + self::REQUIRED, [$variable => $valid]);
            self::assertSame(SpoolReaderConfig::readerIdFor((string) gethostname(), (int) getmypid()), $settings->reader->readerId);

            return;
        }
        $this->expectException(LogicException::class);
        DrainSettings::fromOptions([$option => ''] + self::REQUIRED, [$variable => $valid]);
    }

    public function testEmptyOptionalStringClearsEnvironment(): void
    {
        $settings = DrainSettings::fromOptions(self::REQUIRED + ['pyroscope-tenant' => '', 'pyroscope-user' => ''], [
            'TRACEWICK_PYROSCOPE_TENANT' => 'env-tenant',
            'TRACEWICK_PYROSCOPE_USER' => 'env-user',
        ]);
        self::assertNull($settings->pyroscope->tenantId);
        self::assertNull($settings->pyroscope->basicAuthUser);
    }

    public function testClearedUserCannotKeepAnEnvironmentPassword(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Basic authentication needs both');
        DrainSettings::fromOptions(self::REQUIRED + ['pyroscope-user' => ''], [
            'TRACEWICK_PYROSCOPE_USER' => 'env-user',
            'TRACEWICK_PYROSCOPE_PASSWORD' => 'secret',
        ]);
    }

    public function testEmptyOperationalEnvironmentUsesDefaults(): void
    {
        self::assertEquals(DrainSettings::fromOptions(self::REQUIRED, []), DrainSettings::fromOptions(self::REQUIRED, [
            'TRACEWICK_READER_ID' => '', 'TRACEWICK_LIMIT' => '', 'TRACEWICK_MEMORY_LIMIT' => '',
            'TRACEWICK_TIME_LIMIT_SECONDS' => '', 'TRACEWICK_SLEEP_SECONDS' => '', 'TRACEWICK_AGGREGATION_WINDOW' => '',
        ]));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidEnvironment(): iterable
    {
        yield 'reader' => ['TRACEWICK_READER_ID', 'a/b'];
        yield 'limit zero' => ['TRACEWICK_LIMIT', '0'];
        yield 'limit overflow' => ['TRACEWICK_LIMIT', '999999999999999999999999'];
        yield 'limit fractional' => ['TRACEWICK_LIMIT', '1.5'];
        yield 'memory overflow' => ['TRACEWICK_MEMORY_LIMIT', '8589934592G'];
        yield 'memory zero' => ['TRACEWICK_MEMORY_LIMIT', '0'];
        yield 'time zero' => ['TRACEWICK_TIME_LIMIT_SECONDS', '0'];
        yield 'sleep negative' => ['TRACEWICK_SLEEP_SECONDS', '-1'];
        yield 'sleep upper limit' => ['TRACEWICK_SLEEP_SECONDS', '3601'];
        yield 'sleep duration' => ['TRACEWICK_SLEEP_SECONDS', '1s'];
        yield 'window range' => ['TRACEWICK_AGGREGATION_WINDOW', '16'];
    }

    #[DataProvider('invalidEnvironment')]
    public function testRejectsInvalidEnvironment(string $variable, string $value): void
    {
        $this->expectException(LogicException::class);
        DrainSettings::fromOptions(self::REQUIRED, [$variable => $value]);
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
