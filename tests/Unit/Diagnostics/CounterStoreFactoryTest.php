<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Diagnostics\ApcuCounterStore;
use Tracewick\Diagnostics\CounterStoreFactory;
use Tracewick\Diagnostics\FileCounterStore;
use Tracewick\Transport\AtomicFile;

#[CoversClass(CounterStoreFactory::class)]
#[UsesClass(ApcuCounterStore::class)]
#[UsesClass(FileCounterStore::class)]
#[UsesClass(AtomicFile::class)]
final class CounterStoreFactoryTest extends TestCase
{
    public function testPrefersApcuWhenAvailable(): void
    {
        self::assertInstanceOf(ApcuCounterStore::class, CounterStoreFactory::create(null, static fn(): bool => true));
    }

    public function testFallsBackToFileStoreWhenApcuUnavailable(): void
    {
        $directory = sys_get_temp_dir() . '/tracewick-counter-factory-' . bin2hex(random_bytes(4));
        try {
            $store = CounterStoreFactory::create($directory, static fn(): bool => false);
            self::assertInstanceOf(FileCounterStore::class, $store);
            $store->increment('x');
            self::assertSame(1, $store->get('x'));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testUsesTempDirectoryWhenNoneGivenAndApcuIsUnavailable(): void
    {
        $store = CounterStoreFactory::create(null, static fn(): bool => false);
        self::assertInstanceOf(FileCounterStore::class, $store);
        $key = 'temp-default-' . bin2hex(random_bytes(4));
        $before = $store->get($key);
        $store->increment($key);
        self::assertSame($before + 1, $store->get($key));
    }

    public function testCreateWithoutOverrideUsesRuntimeApcuDetection(): void
    {
        $directory = sys_get_temp_dir() . '/tracewick-counter-factory-' . bin2hex(random_bytes(4));
        try {
            $store = CounterStoreFactory::create($directory);
            self::assertContains($store::class, [ApcuCounterStore::class, FileCounterStore::class]);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $files = glob($directory . '/*');
        if (false !== $files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                } elseif (is_dir($file)) {
                    @rmdir($file);
                }
            }
        }

        @rmdir($directory);
    }
}
