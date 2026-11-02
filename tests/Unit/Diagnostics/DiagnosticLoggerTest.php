<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingLogger;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;

#[CoversClass(DiagnosticLogger::class)]
#[UsesClass(AtomicFile::class)]
#[UsesClass(SystemClock::class)]
final class DiagnosticLoggerTest extends TestCase
{
    private TemporaryDirectory $directory;

    private ManualClock $clock;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
        $this->clock = new ManualClock();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testDoesNothingWithoutALogger(): void
    {
        $logger = new DiagnosticLogger(directory: $this->directory->path);

        self::assertFalse($logger->hasLogger());
        $logger->log(LogLevel::WARNING, 'Failed.');

        self::assertSame([], $this->directory->entries());
    }

    public function testReportsWhetherALoggerIsConfigured(): void
    {
        self::assertTrue($this->diagnosticLogger()->hasLogger());
    }

    public function testPassesTheMessageWithItsContext(): void
    {
        $this->diagnosticLogger()->log(LogLevel::WARNING, 'Failed: {reason}', ['reason' => 'timeout']);

        self::assertSame([['level' => 'warning', 'message' => 'Failed: {reason}', 'context' => ['reason' => 'timeout']]], $this->logger->records);
    }

    public function testWritesAMessageAtMostOncePerInterval(): void
    {
        $logger = $this->diagnosticLogger();

        $logger->log(LogLevel::WARNING, 'Failed: {reason}', ['reason' => 'first']);
        $this->clock->advanceNs(999_999_999);
        $logger->log(LogLevel::WARNING, 'Failed: {reason}', ['reason' => 'second']);
        $logger->log(LogLevel::WARNING, 'Failed: {reason}', ['reason' => 'third']);
        $this->clock->advanceNs(1);
        $logger->log(LogLevel::WARNING, 'Failed: {reason}', ['reason' => 'fourth']);
        $logger->log(LogLevel::WARNING, 'Failed: {reason}', ['reason' => 'fifth']);
        $this->clock->advanceMs(1_000);
        $logger->log(LogLevel::WARNING, 'Failed: {reason}', ['reason' => 'sixth']);

        self::assertSame(
            [['reason' => 'first'], ['reason' => 'fourth', 'suppressed' => 2], ['reason' => 'sixth', 'suppressed' => 1]],
            array_column($this->logger->records, 'context'),
        );
    }

    public function testCountsEachMessageAndLevelOnItsOwn(): void
    {
        $logger = $this->diagnosticLogger();

        $logger->log(LogLevel::WARNING, 'Failed.');
        $logger->log(LogLevel::NOTICE, 'Failed.');
        $logger->log(LogLevel::WARNING, 'Skipped.');
        $logger->log(LogLevel::WARNING, 'Failed.');

        self::assertSame(['warning: Failed.', 'notice: Failed.', 'warning: Skipped.'], $this->logger->lines());
        self::assertCount(3, $this->directory->entries());
    }

    public function testSharesTheIntervalBetweenProcesses(): void
    {
        $this->diagnosticLogger()->log(LogLevel::WARNING, 'Failed.');
        $this->diagnosticLogger()->log(LogLevel::WARNING, 'Failed.');

        self::assertCount(1, $this->logger->records);
        self::assertSame(['tracewick-log-' . md5('warning Failed.')], $this->directory->entries());
        self::assertSame($this->clock->nowNs() . " 1\n", file_get_contents($this->directory->path . '/tracewick-log-' . md5('warning Failed.')));
    }

    #[TestWith([''])]
    #[TestWith(['1 0'])]
    #[TestWith(["1 0\n\n"])]
    #[TestWith([" 0\n"])]
    #[TestWith(["1 \n"])]
    #[TestWith(["x 0\n"])]
    #[TestWith(["12345678901234567890 0\n"])]
    #[TestWith(["1 1234567890\n"])]
    public function testStartsOverFromAnInvalidFile(string $contents): void
    {
        $path = $this->directory->path . '/tracewick-log-' . md5('warning Failed.');
        file_put_contents($path, '' === $contents ? '' : str_replace('1 ', $this->clock->nowNs() . ' ', $contents));

        $this->diagnosticLogger()->log(LogLevel::WARNING, 'Failed.');

        self::assertSame([[]], array_column($this->logger->records, 'context'));
        self::assertSame($this->clock->nowNs() . " 0\n", file_get_contents($path));
    }

    public function testAcceptsTheLongestValues(): void
    {
        $path = $this->directory->path . '/tracewick-log-' . md5('warning Failed.');
        file_put_contents($path, "1000000000000000000 123456789\n");
        $this->clock->advanceMs(1_000);

        $this->diagnosticLogger()->log(LogLevel::WARNING, 'Failed.');

        self::assertSame([['suppressed' => 123_456_789]], array_column($this->logger->records, 'context'));
    }

    public function testWritesEveryCopyWhenTheIntervalCannotBeKept(): void
    {
        $logger = new DiagnosticLogger($this->logger, $this->directory->path . '/missing', 1_000, $this->clock);

        $logger->log(LogLevel::WARNING, 'Failed.');
        $logger->log(LogLevel::WARNING, 'Failed.');

        self::assertCount(2, $this->logger->records);
    }

    public function testSwallowsExceptionsOfTheLogger(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @param mixed              $level
             * @param string|\Stringable $message
             * @param array<mixed>       $context
             */
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('The log is full.');
            }
        };

        (new DiagnosticLogger($logger, $this->directory->path))->log(LogLevel::WARNING, 'Failed.');

        self::assertCount(1, $this->directory->entries());
    }

    public function testKeepsTheIntervalInTheTemporaryDirectoryByDefault(): void
    {
        $message = 'Failed ' . bin2hex(random_bytes(8));
        $path = sys_get_temp_dir() . '/tracewick-log-' . md5('warning ' . $message);

        try {
            (new DiagnosticLogger($this->logger))->log(LogLevel::WARNING, $message);

            self::assertFileExists($path);
            self::assertCount(1, $this->logger->records);
        } finally {
            @unlink($path);
        }
    }

    private function diagnosticLogger(): DiagnosticLogger
    {
        return new DiagnosticLogger($this->logger, $this->directory->path, 1_000, $this->clock);
    }
}
