<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

/**
 * Turns the log of an `ExcimerProfiler` into extension-independent samples.
 *
 * Input from the extension is validated field by field: malformed frames are skipped and malformed
 * entries are dropped instead of failing the whole batch.
 *
 * @internal
 */
final readonly class ExcimerTraceConverter
{
    /**
     * @param int $originNs Unix time in nanoseconds at which the profiler object was constructed,
     *                      the reference point of Excimer timestamps
     * @param int $maxDepth depth limit configured on the profiler, used to detect truncated stacks
     */
    public function __construct(
        private int $originNs,
        private int $maxDepth,
    ) {}

    /**
     * @return list<Sample>
     */
    public function convertLog(\ExcimerLog $log): array
    {
        $samples = [];
        foreach ($log as $entry) {
            if (!$entry instanceof \ExcimerLogEntry) {
                continue;
            }

            $sample = $this->convertEntry($entry->getTrace(), $entry->getEventCount(), $entry->getTimestamp());
            if (null !== $sample) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }

    /**
     * @param array<mixed> $trace     frames as returned by `ExcimerLogEntry::getTrace()`, innermost first
     * @param int          $count     number of sampling periods the entry represents
     * @param float        $timestamp seconds since the profiler object was constructed
     *
     * @return ?Sample null when the entry carries no events
     */
    public function convertEntry(array $trace, int $count, float $timestamp): ?Sample
    {
        if ($count < 1) {
            return null;
        }

        $frames = [];
        foreach ($trace as $rawFrame) {
            if (\is_array($rawFrame)) {
                $frames[] = $this->convertFrame($rawFrame);
            }
        }

        return new Sample(
            array_reverse($frames),
            $count,
            $this->originNs + (int) round($timestamp * 1_000_000_000),
            \count($trace) >= $this->maxDepth,
        );
    }

    /**
     * @param array<mixed> $frame
     */
    private function convertFrame(array $frame): Frame
    {
        return new Frame(
            self::stringOrNull($frame['function'] ?? null),
            self::stringOrNull($frame['class'] ?? null),
            self::stringOrNull($frame['file'] ?? null),
            \is_int($frame['line'] ?? null) ? $frame['line'] : 0,
            \is_int($frame['closure_line'] ?? null) ? $frame['closure_line'] : null,
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
