<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Diagnostics\FileCounterStore;
use Tracewick\Transport\AtomicFile;

#[CoversClass(FileCounterStore::class)]
#[UsesClass(AtomicFile::class)]
final class FileCounterStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/tracewick-counter-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }

        $files = glob($this->directory . '/*');
        if (false !== $files) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        @rmdir($this->directory);
    }

    public function testIncrementsAndReadsAcrossInstances(): void
    {
        $store = FileCounterStore::inDirectory($this->directory);
        $store->increment('a', 2);
        $store->increment('a');
        $store->increment('b');

        $again = FileCounterStore::inDirectory($this->directory);
        self::assertSame(3, $again->get('a'));
        self::assertSame(1, $again->get('b'));
        self::assertSame(0, $again->get('missing'));
        self::assertSame(['a' => 3, 'b' => 1], $again->all());
    }

    public function testIgnoresNonPositiveIncrements(): void
    {
        $store = FileCounterStore::inDirectory($this->directory);
        $store->increment('a', 0);
        $store->increment('a', -1);
        $store->increment('');

        self::assertSame([], $store->all());
    }

    public function testTreatsCorruptFilesAsEmpty(): void
    {
        @mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/counters.json', 'not-json');

        $store = FileCounterStore::inDirectory($this->directory);
        self::assertSame([], $store->all());
        $store->increment('ok');
        self::assertSame(['ok' => 1], $store->all());
    }

}
