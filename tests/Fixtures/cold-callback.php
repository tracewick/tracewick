<?php

declare(strict_types=1);

use Tracewick\Diagnostics\DiagnosticLogger;
use Tracewick\Profile\Profile;
use Tracewick\Runtime\ProfilerConfig;
use Tracewick\Runtime\UnitOfWorkProfiler;
use Tracewick\Sampler\ExcimerSampler;
use Tracewick\Sampler\SamplerConfig;
use Tracewick\Sink\Sink;
use Tracewick\Tests\Support\ManualClock;
use Tracewick\Tests\Support\RecordingLogger;
use Tracewick\Transport\Delivery;

require __DIR__ . '/../../vendor/autoload.php';

$mode = $argv[1] ?? 'sampler';
$clock = new ManualClock(1_000_000_000);
$sampler = new ExcimerSampler(new SamplerConfig(periodNs: 1_000_000, maxSamples: 1));
$runtime = null;
if ('sampler' !== $mode) {
    $sink = new class implements Sink {
        public function send(Profile $profile): Delivery
        {
            return Delivery::delivered();
        }
    };
    $runtime = new UnitOfWorkProfiler(
        $sampler,
        $sink,
        new ProfilerConfig(maxBufferedProfiles: 1, sampleRate: 1.0, maxProfileDurationSeconds: 1),
        logger: new DiagnosticLogger(new RecordingLogger()),
        clock: $clock,
    );
}

$native = (new ReflectionProperty($sampler, 'profiler'))->getValue($sampler);
if (!$native instanceof ExcimerProfiler) {
    throw new RuntimeException('Expected real Excimer profiler.');
}
$original = (new ReflectionMethod($sampler, 'onNativeFlush'))->getClosure($sampler);
$state = new class {
    public int $depth = 0;

    public int $events = 0;

    /** @var array<string, true> */
    public array $autoloads = [];
};
$observer = static function (string $class) use ($state): void {
    if ($state->depth > 0) {
        $state->autoloads[$class] = true;
    }
};
spl_autoload_register($observer, true, true);
$native->setFlushCallback(static function (ExcimerLog $log) use ($original, $state): void {
    ++$state->depth;
    try {
        $state->events += $log->getEventCount();
        $original($log);
    } finally {
        --$state->depth;
    }
}, 1);

try {
    if (!$runtime instanceof UnitOfWorkProfiler) {
        $sampler->start();
        $deadline = hrtime(true) + 30_000_000;
        while (hrtime(true) < $deadline) {
        }
        $sampler->stop();
        $sampler->flush();
    } else {
        $runtime->start();
        $rotations = 'overflow' === $mode ? 3 : 1;
        for ($rotation = 1; $rotation <= $rotations; ++$rotation) {
            $clock->advanceNs(2_000_000_000);
            $deadline = hrtime(true) + 1_000_000_000;
            while ($runtime->diagnostics()->collectedProfiles < $rotation && hrtime(true) < $deadline) {
            }
        }
        $runtime->stop();
    }
} finally {
    $sampler->stop();
    spl_autoload_unregister($observer);
}

echo json_encode([
    'autoloads' => array_keys($state->autoloads),
    'events' => $state->events,
    'collected' => $runtime?->diagnostics()->collectedProfiles ?? 0,
    'dropped' => $runtime?->diagnostics()->droppedProfiles ?? 0,
], JSON_THROW_ON_ERROR), "\n";
