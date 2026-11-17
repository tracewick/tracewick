<?php

declare(strict_types=1);

namespace Tracewick\Tests\Integration\Runtime;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Tracewick\Tests\Support\TemporaryDirectory;

#[CoversNothing]
final class DrainEnvironmentTest extends TestCase
{
    public function testExecutableReadsEnvironmentAndHonorsExplicitArguments(): void
    {
        $spool = new TemporaryDirectory();
        try {
            $environment = [
                'TRACEWICK_SPOOL_DIR' => $spool->path,
                'TRACEWICK_PYROSCOPE_URL' => 'http://127.0.0.1:1',
                'TRACEWICK_READER_ID' => 'env-process',
                'TRACEWICK_LIMIT' => '1',
                'TRACEWICK_MEMORY_LIMIT' => '128M',
                'TRACEWICK_TIME_LIMIT_SECONDS' => '5',
                'TRACEWICK_SLEEP_SECONDS' => 'bad',
            ];
            [$code, $output, $errors] = $this->execute(['--once'], $environment);
            self::assertSame(2, $code);
            self::assertStringContainsString('--sleep', $errors);
            [$code, $output, $errors] = $this->execute(['--once', '--sleep=0'], $environment);
            self::assertSame(0, $code, $errors);
            self::assertStringContainsString('Delivered 0', $output);
            [$code, , $errors] = $this->execute(['--once', '--spool-dir=', '--sleep=0'], $environment);
            self::assertSame(2, $code);
            self::assertStringContainsString('spool directory is required', $errors);
            [$code, $output] = $this->execute(['--help'], $environment);
            self::assertSame(0, $code);
            self::assertStringContainsString('TRACEWICK_SLEEP_SECONDS', $output);
        } finally {
            $spool->remove();
        }
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{int, string, string}
     */
    private function execute(array $arguments, array $environment): array
    {
        $process = proc_open(
            [\PHP_BINARY, __DIR__ . '/../../../bin/tracewick-drain', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            env_vars: $environment,
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

        return [proc_close($process), $output, $errors];
    }
}
