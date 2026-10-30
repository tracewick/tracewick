<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\CircuitBreakerState;
use Tracewick\Transport\FileCircuitBreakerStore;

#[CoversClass(FileCircuitBreakerStore::class)]
#[UsesClass(CircuitBreakerState::class)]
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
        self::assertSame(['state'], $this->directory->entries());

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
        $store = new FileCircuitBreakerStore($this->directory->path . '/missing/state');

        $store->save(new CircuitBreakerState(3, 1));

        self::assertEquals(new CircuitBreakerState(), $store->load());
        self::assertSame([], $this->directory->entries());
    }

    public function testRemovesTheTemporaryFileWhenTheRenameFails(): void
    {
        mkdir($this->directory->path . '/state');

        $this->store()->save(new CircuitBreakerState(3, 1));

        self::assertSame(['state'], $this->directory->entries());
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

    private function store(): FileCircuitBreakerStore
    {
        return new FileCircuitBreakerStore($this->directory->path . '/state');
    }
}
