<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Starts tests/Support/fake-http-server.php in a child process and reads back what it received.
 *
 * A separate process is needed because the transport under test blocks while it waits for the
 * response, so the server cannot run in the test's own process.
 */
final class FakeHttpServer
{
    /** @var resource */
    private $process;

    /** @var resource */
    private $stdout;

    private readonly string $logFile;

    private readonly int $port;

    /**
     * @param list<array<string, bool|int|string>> $scenario one action per connection, see the script
     */
    public function __construct(array $scenario)
    {
        $logFile = tempnam(sys_get_temp_dir(), 'tracewick-http-');
        Assert::assertIsString($logFile);
        $this->logFile = $logFile;

        $pipes = [];
        $process = proc_open(
            [\PHP_BINARY, __DIR__ . '/fake-http-server.php', $this->logFile, json_encode($scenario, \JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        Assert::assertIsResource($process, 'Cannot start the fake HTTP server.');
        $this->process = $process;
        $this->stdout = $pipes[1] ?? Assert::fail('The fake HTTP server has no output pipe.');

        $port = fgets($this->stdout);
        Assert::assertIsString($port, 'The fake HTTP server did not report its port.');
        $this->port = (int) $port;
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function url(string $path = '/'): string
    {
        return \sprintf('http://127.0.0.1:%d%s', $this->port, $path);
    }

    /**
     * @return list<string> raw requests in the order they were received
     */
    public function requests(): array
    {
        $lines = file($this->logFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        Assert::assertIsArray($lines);

        return array_map(static fn(string $line): string => (string) base64_decode($line, true), $lines);
    }

    /**
     * Returns one request the server received, counted from zero.
     */
    public function request(int $index): string
    {
        return $this->requests()[$index] ?? Assert::fail(\sprintf('The server did not receive %d requests.', $index + 1));
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            fclose($this->stdout);
            proc_close($this->process);
        }

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }
}
