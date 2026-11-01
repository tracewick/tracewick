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
     * @param int     $originNs     Unix time in nanoseconds at which the profiler object was constructed,
     *                              the reference point of Excimer timestamps
     * @param int     $maxDepth     depth limit configured on the profiler, used to detect truncated stacks
     * @param ?string $projectRoot  absolute app base stripped from frame paths; null keeps Excimer paths
     */
    public function __construct(
        private int $originNs,
        private int $maxDepth,
        private ?string $projectRoot = null,
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
            if ($sample instanceof Sample) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }

    /**
     * Feeds a log into an aggregator without building one Sample per Excimer entry.
     */
    public function ingestLog(\ExcimerLog $log, StackAggregator $aggregator): void
    {
        foreach ($log as $entry) {
            if (!$entry instanceof \ExcimerLogEntry) {
                continue;
            }

            $this->ingestEntry($entry->getTrace(), $entry->getEventCount(), $aggregator);
        }
    }

    /**
     * @param array<mixed> $trace innermost first
     */
    public function ingestEntry(array $trace, int $count, StackAggregator $aggregator): void
    {
        if ($count < 1) {
            return;
        }

        $truncated = \count($trace) >= $this->maxDepth;
        $trace = \array_slice($trace, 0, $this->maxDepth);

        $frames = [];
        foreach ($trace as $rawFrame) {
            if (\is_array($rawFrame)) {
                $frames[] = $this->convertFrame($rawFrame);
            }
        }

        $aggregator->add(array_reverse($frames), $count, $truncated);
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

        // Excimer may append a marker frame when it cuts a stack at the depth limit; whatever
        // lies beyond the limit is dropped so that the limit holds for every consumer.
        $truncated = \count($trace) >= $this->maxDepth;
        $trace = \array_slice($trace, 0, $this->maxDepth);

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
            $truncated,
        );
    }

    /**
     * @param array<mixed> $frame
     */
    private function convertFrame(array $frame): Frame
    {
        $function = $this->stringOrNull($frame['function'] ?? null);
        $file = $this->stripPath($this->stringOrNull($frame['file'] ?? null));

        return new Frame(
            $this->stripPathInFunction($function),
            $this->stringOrNull($frame['class'] ?? null),
            $file,
            \is_int($frame['line'] ?? null) ? $frame['line'] : 0,
            \is_int($frame['closure_line'] ?? null) ? $frame['closure_line'] : null,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function stripPath(?string $path): ?string
    {
        if (null === $path || null === $this->projectRoot) {
            return $path;
        }

        if ($path === $this->projectRoot) {
            return '';
        }

        $prefix = $this->projectRoot . '/';
        if (str_starts_with($path, $prefix)) {
            return substr($path, \strlen($prefix));
        }

        return $path;
    }

    private function stripPathInFunction(?string $function): ?string
    {
        if (null === $function || null === $this->projectRoot) {
            return $function;
        }

        if (!str_starts_with($function, '{closure:') || !str_ends_with($function, '}')) {
            return $function;
        }

        $inner = substr($function, \strlen('{closure:'), -1);
        $colon = strrpos($inner, ':');
        if (false === $colon) {
            return $function;
        }

        $scope = substr($inner, 0, $colon);
        $line = substr($inner, $colon + 1);
        if ('' === $line || !ctype_digit($line)) {
            return $function;
        }

        // Path-shaped scopes only; Class::method() / foo() keep their form for Frame::name().
        if (!str_contains($scope, '/') && !str_starts_with($scope, '\\')) {
            return $function;
        }

        $stripped = $this->stripPath($scope);

        return '{closure:' . ($stripped ?? $scope) . ':' . $line . '}';
    }
}
