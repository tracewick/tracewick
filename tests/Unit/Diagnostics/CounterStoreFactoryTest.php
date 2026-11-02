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
    public function testPrefersApcuWhenEnabled(): void
    {
        if (!\extension_loaded('apcu') || !apcu_enabled()) {
            self::markTestSkipped('APCu is not enabled.');
        }

        self::assertInstanceOf(ApcuCounterStore::class, CounterStoreFactory::create());
    }

    public function testFallsBackToFileStore(): void
    {
        if (\extension_loaded('apcu') && apcu_enabled()) {
            self::markTestSkipped('APCu is enabled; file fallback is not selected.');
        }

        $directory = sys_get_temp_dir() . '/tracewick-counter-factory-' . bin2hex(random_bytes(4));
        try {
            $store = CounterStoreFactory::create($directory);
            self::assertInstanceOf(FileCounterStore::class, $store);
            $store->increment('x');
            self::assertSame(1, $store->get('x'));
        } finally {
            $files = glob($directory . '/*');
            if (false !== $files) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }

            @rmdir($directory);
        }
    }
}
