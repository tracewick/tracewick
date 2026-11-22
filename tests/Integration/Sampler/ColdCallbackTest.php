<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Sampler;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
#[RequiresPhpExtension('excimer')]
final class ColdCallbackTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function modes(): iterable
    {
        yield 'native ingestion' => ['sampler', 0];
        yield 'automatic rotation' => ['rotation', 1];
        yield 'buffer overflow' => ['overflow', 3];
    }

    #[DataProvider('modes')]
    public function testColdCallbacksDoNotAutoload(string $mode, int $rotations): void
    {
        // PHPUnit metadata and random test order can preload the very classes under test.
        $process = proc_open(
            [\PHP_BINARY, __DIR__ . '/../../Fixtures/cold-callback.php', $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        self::assertArrayHasKey(0, $pipes);
        self::assertArrayHasKey(1, $pipes);
        self::assertArrayHasKey(2, $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertIsString($output);
        self::assertIsString($errors);
        self::assertSame(0, proc_close($process), $errors);
        $result = json_decode($output, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertSame([], $result['autoloads'] ?? null);
        self::assertGreaterThan(0, $result['events'] ?? 0);
        self::assertGreaterThanOrEqual($rotations, $result['collected'] ?? -1);
        if ($rotations > 1) {
            self::assertGreaterThan(0, $result['dropped'] ?? 0);
        }
    }
}
