<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\FileCircuitBreakerStore;

#[CoversClass(FileCircuitBreakerStore::class)]
#[UsesClass(CircuitBreakerState::class)]
#[UsesClass(AtomicFile::class)]
final class FileCircuitBreakerStoreTest extends TestCase
{
    private TemporaryDirectory $directory;

    protected function setUp(): void
    {
        $this->directory = new TemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
    }

    public function testReadsAMissingFileAsAClosedCircuit(): void
    {
        self::assertEquals(new CircuitBreakerState(), $this->store()->load());
    }

    public function testSharesTheStateThroughTheFile(): void
    {
        $this->store()->save(new CircuitBreakerState(3, 1_793_338_892_820_000_000));

        self::assertEquals(new CircuitBreakerState(3, 1_793_338_892_820_000_000), $this->store()->load());
        self::assertSame("3 1793338892820000000\n", file_get_contents($this->directory->path . '/state'));
        self::assertTrue($this->store()->persisted(new CircuitBreakerState(3, 1_793_338_892_820_000_000)));

        $this->store()->save(new CircuitBreakerState(1));

        self::assertSame(1, $this->store()->load()->consecutiveFailures);
        self::assertNull($this->store()->load()->openUntilNs);
        self::assertSame("1 -\n", file_get_contents($this->directory->path . '/state'));
    }

    #[TestWith([''])]
    #[TestWith(['3 -'])]
    #[TestWith(["3 -\nmore"])]
    #[TestWith(["x 1\n"])]
    #[TestWith([" 5\n"])]
    #[TestWith(["3 -\n\n"])]
    #[TestWith(["3 \n"])]
    #[TestWith([" 3 -\n"])]
    #[TestWith(["3 1x\n"])]
    #[TestWith(["1234567890 -\n"])]
    #[TestWith(["1 12345678901234567890\n"])]
    public function testReadsAnInvalidFileAsAClosedCircuit(string $contents): void
    {
        file_put_contents($this->directory->path . '/state', $contents);

        self::assertEquals(new CircuitBreakerState(), $this->store()->load());
    }

    public function testAcceptsTheLongestValues(): void
    {
        file_put_contents($this->directory->path . '/state', "123456789 1234567890123456789\n");

        self::assertEquals(new CircuitBreakerState(123_456_789, 1_234_567_890_123_456_789), $this->store()->load());
    }

    public function testForgetsTheStateWhenTheFileCannotBeWritten(): void
    {
        file_put_contents($this->directory->path . '/blocked', 'x');
        $store = new FileCircuitBreakerStore($this->directory->path . '/blocked/state');

        $store->save(new CircuitBreakerState(3, 1));

        self::assertEquals(new CircuitBreakerState(), $store->load());
        self::assertFalse($store->persisted(new CircuitBreakerState(3, 1)));
    }

    public function testRemovesTheTemporaryFileWhenTheRenameFails(): void
    {
        mkdir($this->directory->path . '/state');

        $this->store()->save(new CircuitBreakerState(3, 1));

        self::assertSame(['state'], $this->directory->entries());
    }

    public function testCreatesTheDestinationDirectoryWithOwnerOnlyPermissions(): void
    {
        $nested = $this->directory->path . '/state-dir';
        FileCircuitBreakerStore::forDestination($nested, 'http://a')->save(new CircuitBreakerState(1));

        self::assertDirectoryExists($nested);
        self::assertSame(0o700, fileperms($nested) & 0o777);
    }

    public function testKeepsOneFilePerDestination(): void
    {
        FileCircuitBreakerStore::forDestination($this->directory->path, 'http://a')->save(new CircuitBreakerState(1));
        FileCircuitBreakerStore::forDestination($this->directory->path, 'http://b')->save(new CircuitBreakerState(2));

        self::assertSame(
            ['tracewick-circuit-' . md5('http://a'), 'tracewick-circuit-' . md5('http://b')],
            $this->directory->entries(),
        );
        self::assertEquals(new CircuitBreakerState(1), FileCircuitBreakerStore::forDestination($this->directory->path, 'http://a')->load());
    }

    public function testUpdateAppliesChangesUnderALock(): void
    {
        $store = $this->store();
        $state = $store->update(static fn(CircuitBreakerState $current): CircuitBreakerState => new CircuitBreakerState(2, 99));

        self::assertEquals(new CircuitBreakerState(2, 99), $state);
        self::assertEquals(new CircuitBreakerState(2, 99), $store->load());
        self::assertFileExists($this->directory->path . '/state.lock');
    }

    public function testUpdateLeavesStateUnchangedWhenTheLockFileCannotBeOpened(): void
    {
        $path = $this->directory->path . '/state';
        $store = new FileCircuitBreakerStore($path);
        $store->save(new CircuitBreakerState(1));
        mkdir($path . '.lock');

        $state = $store->update(static fn(CircuitBreakerState $current): CircuitBreakerState => new CircuitBreakerState(3, 11));

        self::assertEquals(new CircuitBreakerState(1), $state);
        self::assertEquals(new CircuitBreakerState(1), $store->load());
    }

    public function testUpdateLeavesStateUnchangedWhenTheLockIsBusy(): void
    {
        $path = $this->directory->path . '/state';
        $store = new FileCircuitBreakerStore($path);
        $store->save(new CircuitBreakerState(1));

        $holder = fopen($path . '.lock', 'c+');
        self::assertIsResource($holder);
        self::assertTrue(flock($holder, \LOCK_EX));

        try {
            $state = $store->update(static fn(CircuitBreakerState $current): CircuitBreakerState => new CircuitBreakerState(3, 11));

            self::assertEquals(new CircuitBreakerState(1), $state);
            self::assertEquals(new CircuitBreakerState(1), $store->load());
        } finally {
            flock($holder, \LOCK_UN);
            fclose($holder);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testConcurrentUpdatesSerializeThroughTheLock(): void
    {
        $path = $this->directory->path . '/state';
        $store = new FileCircuitBreakerStore($path);
        $store->save(new CircuitBreakerState());

        $children = [];
        for ($i = 0; $i < 2; ++$i) {
            $pid = pcntl_fork();
            if (-1 === $pid) {
                self::fail('pcntl_fork failed.');
            }
            if (0 === $pid) {
                $child = new FileCircuitBreakerStore($path);
                for ($n = 0; $n < 20; ++$n) {
                    $child->update(static fn(CircuitBreakerState $state): CircuitBreakerState => new CircuitBreakerState($state->consecutiveFailures + 1));
                }
                exit(0);
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        self::assertSame(40, $store->load()->consecutiveFailures);
    }

    private function store(): FileCircuitBreakerStore
    {
        return new FileCircuitBreakerStore($this->directory->path . '/state');
    }
}
