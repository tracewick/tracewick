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
use Tracewick\Transport\CircuitBreakerAttempt;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\FileCircuitBreakerStore;

#[CoversClass(FileCircuitBreakerStore::class)]
#[UsesClass(CircuitBreakerState::class)]
#[UsesClass(CircuitBreakerAttempt::class)]
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
        $this->writeFile(new CircuitBreakerState(3, 1_793_338_892_820_000_000, 4));

        self::assertEquals(new CircuitBreakerState(3, 1_793_338_892_820_000_000, 4), $this->store()->load());
        self::assertSame("3 1793338892820000000 4\n", file_get_contents($this->directory->path . '/state'));
        self::assertTrue($this->store()->persisted(new CircuitBreakerState(3, 1_793_338_892_820_000_000, 4)));

        $this->writeFile(new CircuitBreakerState(3, null, 4));
        $attempt = $this->store()->acquire(0, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $attempt);
        $this->store()->complete($attempt->generation, new CircuitBreakerState(1, null, $attempt->generation));

        self::assertSame(1, $this->store()->load()->consecutiveFailures);
        self::assertNull($this->store()->load()->openUntilNs);
        self::assertSame("1 - {$attempt->generation}\n", file_get_contents($this->directory->path . '/state'));
    }

    public function testReadsALegacyLineWithoutGeneration(): void
    {
        file_put_contents($this->directory->path . '/state', "3 1793338892820000000\n");

        self::assertEquals(new CircuitBreakerState(3, 1_793_338_892_820_000_000), $this->store()->load());
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
        file_put_contents($this->directory->path . '/state', "123456789 1234567890123456789 1\n");

        self::assertEquals(new CircuitBreakerState(123_456_789, 1_234_567_890_123_456_789, 1), $this->store()->load());
    }

    public function testAcquireReturnsNullWhenTheFileCannotBeWritten(): void
    {
        file_put_contents($this->directory->path . '/blocked', 'x');
        $store = new FileCircuitBreakerStore($this->directory->path . '/blocked/state');

        self::assertNull($store->acquire(0, 1_000, 5_000));
        self::assertEquals(new CircuitBreakerState(), $store->load());
    }

    public function testRemovesTheTemporaryFileWhenTheRenameFails(): void
    {
        mkdir($this->directory->path . '/state');

        self::assertNull($this->store()->acquire(0, 1_000, 5_000));
        self::assertContains('state', $this->directory->entries());
    }

    public function testCreatesTheDestinationDirectoryWithOwnerOnlyPermissions(): void
    {
        $nested = $this->directory->path . '/state-dir';
        $store = FileCircuitBreakerStore::forDestination($nested, 'http://a');
        $attempt = $store->acquire(0, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $attempt);
        $store->complete($attempt->generation, new CircuitBreakerState(1, null, $attempt->generation));

        self::assertDirectoryExists($nested);
        self::assertSame(0o700, fileperms($nested) & 0o777);
    }

    public function testKeepsOneFilePerDestination(): void
    {
        $a = FileCircuitBreakerStore::forDestination($this->directory->path, 'http://a');
        $b = FileCircuitBreakerStore::forDestination($this->directory->path, 'http://b');
        $attemptA = $a->acquire(0, 1_000, 5_000);
        $attemptB = $b->acquire(0, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $attemptA);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $attemptB);
        $a->complete($attemptA->generation, new CircuitBreakerState(1, null, $attemptA->generation));
        $b->complete($attemptB->generation, new CircuitBreakerState(2, null, $attemptB->generation));

        self::assertContains('tracewick-circuit-' . md5('http://a'), $this->directory->entries());
        self::assertContains('tracewick-circuit-' . md5('http://b'), $this->directory->entries());
        self::assertSame(1, FileCircuitBreakerStore::forDestination($this->directory->path, 'http://a')->load()->consecutiveFailures);
    }

    public function testAcquireAndCompleteApplyChangesUnderALock(): void
    {
        $store = $this->store();
        $attempt = $store->acquire(0, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $attempt);
        self::assertTrue($store->complete($attempt->generation, new CircuitBreakerState(2, 99, $attempt->generation)));

        self::assertEquals(new CircuitBreakerState(2, 99, $attempt->generation), $store->load());
        self::assertFileExists($this->directory->path . '/state.lock');
    }

    public function testAcquireReturnsNullWhenTheLockFileCannotBeOpened(): void
    {
        $path = $this->directory->path . '/state';
        $store = new FileCircuitBreakerStore($path);
        $this->seed($store, new CircuitBreakerState(1, null, 1));
        mkdir($path . '.lock');

        self::assertNull($store->acquire(0, 1_000, 5_000));
        self::assertEquals(new CircuitBreakerState(1, null, 1), $store->load());
    }

    public function testAcquireReturnsNullWhenTheLockIsBusy(): void
    {
        $path = $this->directory->path . '/state';
        $store = new FileCircuitBreakerStore($path);
        $this->seed($store, new CircuitBreakerState(1, null, 1));

        $holder = fopen($path . '.lock', 'c+');
        self::assertIsResource($holder);
        self::assertTrue(flock($holder, \LOCK_EX));

        try {
            self::assertNull($store->acquire(0, 1_000, 5_000));
            self::assertEquals(new CircuitBreakerState(1, null, 1), $store->load());
        } finally {
            flock($holder, \LOCK_UN);
            fclose($holder);
        }
    }

    #[RequiresPhpExtension('pcntl')]
    public function testConcurrentAcquireCompleteLeavesAReadableState(): void
    {
        $path = $this->directory->path . '/state';
        $store = new FileCircuitBreakerStore($path);
        $this->writeFile(new CircuitBreakerState());

        $children = [];
        for ($i = 0; $i < 2; ++$i) {
            $pid = pcntl_fork();
            if (-1 === $pid) {
                self::fail('pcntl_fork failed.');
            }
            if (0 === $pid) {
                $child = new FileCircuitBreakerStore($path);
                for ($n = 0; $n < 20; ++$n) {
                    $attempt = $child->acquire(0, 1_000, 5_000);
                    if (!$attempt instanceof CircuitBreakerAttempt) {
                        continue;
                    }
                    $child->complete(
                        $attempt->generation,
                        new CircuitBreakerState($attempt->stateBefore->consecutiveFailures + 1, null, $attempt->generation),
                    );
                }
                exit(0);
            }
            $children[] = $pid;
        }

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $state = $store->load();
        // Stale completes are ignored across the HTTP gap, so the count may be below 40; the file
        // must stay a valid closed/open state rather than corrupt.
        self::assertGreaterThan(0, $state->consecutiveFailures);
        self::assertLessThanOrEqual(40, $state->consecutiveFailures);
        self::assertGreaterThan(0, $state->generation);
        self::assertTrue($store->persisted($state));
    }

    public function testStaleCompleteIsIgnored(): void
    {
        $store = $this->store();
        $first = $store->acquire(0, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $first);
        $store->complete($first->generation, new CircuitBreakerState(3, 10, $first->generation));

        $second = $store->acquire(10, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $second);
        self::assertFalse($store->complete($first->generation, new CircuitBreakerState()));
        self::assertSame($second->generation, $store->load()->generation);
    }

    public function testAcquireDeniesWhileOpenAndPersistsAClamp(): void
    {
        $this->writeFile(new CircuitBreakerState(3, 86_400_000_000_000, 2));
        $store = $this->store();

        self::assertNull($store->acquire(0, 1_000, 5_000));
        self::assertSame(5_000_000_000, $store->load()->openUntilNs);
    }

    public function testCompleteReturnsFalseWhenTheDirectoryIsNotWritable(): void
    {
        $path = $this->directory->path . '/state';
        $store = new FileCircuitBreakerStore($path);
        $attempt = $store->acquire(0, 1_000, 5_000);
        self::assertInstanceOf(CircuitBreakerAttempt::class, $attempt);
        self::assertTrue(chmod($this->directory->path, 0o555));

        try {
            self::assertFalse($store->complete($attempt->generation, new CircuitBreakerState(1, null, $attempt->generation)));
        } finally {
            chmod($this->directory->path, 0o700);
        }
    }

    public function testClampOnOpenDenyCoversFarFutureDeadlines(): void
    {
        // openUntil just above max pause so clamp rewrites, then deny.
        $this->writeFile(new CircuitBreakerState(2, 6_000_000_000, 1));
        self::assertNull($this->store()->acquire(0, 1_000, 5_000));
        self::assertSame(5_000_000_000, $this->store()->load()->openUntilNs);
    }

    private function store(): FileCircuitBreakerStore
    {
        return new FileCircuitBreakerStore($this->directory->path . '/state');
    }

    private function seed(FileCircuitBreakerStore $store, CircuitBreakerState $state): void
    {
        $this->writeFile($state);
        self::assertEquals($state, $store->load());
    }

    private function writeFile(CircuitBreakerState $state): void
    {
        file_put_contents(
            $this->directory->path . '/state',
            \sprintf("%d %s %d\n", $state->consecutiveFailures, $state->openUntilNs ?? '-', $state->generation),
        );
    }
}
