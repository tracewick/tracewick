<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Tracewick\Clock\SystemClock;
use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingLogger;

#[CoversClass(DiagnosticLogger::class)]
#[UsesClass(SystemClock::class)]
final class DiagnosticLoggerTest extends TestCase
{
    private ManualClock $clock;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        DiagnosticLogger::resetRateLimits();
        $this->clock = new ManualClock();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        DiagnosticLogger::resetRateLimits();
    }

    public function testDoesNothingWithoutALogger(): void
    {
        $logger = new DiagnosticLogger();

        self::assertFalse($logger->hasLogger());
        $logger->log(LogLevel::WARNING, 'Failed.');
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
    }

    public function testSharesTheIntervalBetweenInstancesInTheSameProcess(): void
    {
        $this->diagnosticLogger()->log(LogLevel::WARNING, 'Failed.');
        $this->diagnosticLogger()->log(LogLevel::WARNING, 'Failed.');

        self::assertCount(1, $this->logger->records);
    }

    public function testIgnoresTheLegacyDirectoryArgument(): void
    {
        $logger = new DiagnosticLogger($this->logger, '/no/such/directory', 1_000, $this->clock);

        $logger->log(LogLevel::WARNING, 'Failed.');
        $logger->log(LogLevel::WARNING, 'Failed.');

        self::assertCount(1, $this->logger->records);
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

        $diagnostic = new DiagnosticLogger($logger);
        $diagnostic->log(LogLevel::WARNING, 'Failed.');

        self::assertTrue($diagnostic->hasLogger());
    }

    private function diagnosticLogger(): DiagnosticLogger
    {
        return new DiagnosticLogger($this->logger, intervalMs: 1_000, clock: $this->clock);
    }
}
