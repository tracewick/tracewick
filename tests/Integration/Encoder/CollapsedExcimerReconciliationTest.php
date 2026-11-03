<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Encoder;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Encoder\CollapsedEncoder;
use Tracewick\Profile\BoundedProfileBuilder;
use Tracewick\Profile\Labels;
use Tracewick\Profile\Profile;
use Tracewick\Profile\Stack;
use Tracewick\Sampler\EventType;
use Tracewick\Sampler\ExcimerTraceConverter;
use Tracewick\Sampler\Frame;
use Tracewick\Sampler\Sample;
use Tracewick\Sampler\SampleBatch;

/**
 * Cross-checks the whole pipeline (converter, profile, collapsed encoder) against the collapsed
 * output that ext-excimer produces itself from the same log.
 *
 * Both outputs must account for the same samples. Frame names are compared only for named
 * methods, because Excimer and Tracewick name top-level code and closures differently.
 */
#[CoversClass(CollapsedEncoder::class)]
#[CoversClass(ExcimerTraceConverter::class)]
#[UsesClass(Profile::class)]
#[UsesClass(BoundedProfileBuilder::class)]
#[UsesClass(Stack::class)]
#[UsesClass(Labels::class)]
#[UsesClass(Frame::class)]
#[UsesClass(Sample::class)]
#[UsesClass(SampleBatch::class)]
#[RequiresPhpExtension('excimer')]
final class CollapsedExcimerReconciliationTest extends TestCase
{
    private const PERIOD_NS = 1_000_000;

    public function testAccountsForTheSameSamplesAsExcimer(): void
    {
        $log = $this->profile();

        $ours = $this->parse((new CollapsedEncoder())->encode($this->toProfile($log)));
        $theirs = $this->parse($log->formatCollapsed());

        self::assertGreaterThan(0, array_sum($theirs));
        self::assertSame(array_sum($theirs), array_sum($ours));
    }

    public function testAttributesTheSameSamplesToEachMethod(): void
    {
        $log = $this->profile();

        $ours = $this->parse((new CollapsedEncoder())->encode($this->toProfile($log)));
        $theirs = $this->parse($log->formatCollapsed());

        foreach (['spinA', 'spinB'] as $method) {
            $frame = self::class . '::' . $method;
            $expected = $this->countContaining($theirs, $frame);

            self::assertGreaterThan(0, $expected, \sprintf('Excimer saw no samples in %s.', $method));
            self::assertSame($expected, $this->countContaining($ours, $frame), $method);
        }
    }

    private function profile(): \ExcimerLog
    {
        $profiler = new \ExcimerProfiler();
        $profiler->setEventType(EXCIMER_REAL);
        $profiler->setPeriod(self::PERIOD_NS / 1_000_000_000);
        $profiler->start();

        for ($i = 0; $i < 5; ++$i) {
            $this->spinA();
            $this->spinB();
        }

        $profiler->stop();

        return $profiler->getLog();
    }

    private function toProfile(\ExcimerLog $log): Profile
    {
        // A depth limit far above the test's stack depth, so that nothing is marked truncated.
        $samples = (new ExcimerTraceConverter(0, 4096))->convertLog($log);

        return Profile::fromBatch(new SampleBatch(EventType::Wall, self::PERIOD_NS, 0, 1, $samples));
    }

    /**
     * @return array<string, int> sample count by stack
     */
    private function parse(string $collapsed): array
    {
        $counts = [];
        foreach (explode("\n", trim($collapsed)) as $line) {
            $separator = strrpos($line, ' ');
            if (false === $separator) {
                continue;
            }

            $stack = substr($line, 0, $separator);
            $counts[$stack] = ($counts[$stack] ?? 0) + (int) substr($line, $separator + 1);
        }

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     */
    private function countContaining(array $counts, string $frame): int
    {
        $total = 0;
        foreach ($counts as $stack => $count) {
            // Internal functions such as hrtime() may sit below the method in the stack.
            if (str_contains($stack . ';', ';' . $frame . ';')) {
                $total += $count;
            }
        }

        return $total;
    }

    private function spinA(): void
    {
        $deadline = hrtime(true) + 6_000_000;
        while (hrtime(true) < $deadline) {
            // Keep the CPU busy so that the timer fires in this method.
        }
    }

    private function spinB(): void
    {
        $deadline = hrtime(true) + 4_000_000;
        while (hrtime(true) < $deadline) {
            // Keep the CPU busy so that the timer fires in this method.
        }
    }
}
