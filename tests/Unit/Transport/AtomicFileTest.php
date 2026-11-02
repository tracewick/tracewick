<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Tests\Support\TemporaryDirectory;
use Tracewick\Transport\AtomicFile;

#[CoversClass(AtomicFile::class)]
final class AtomicFileTest extends TestCase
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

    public function testCreatesAndReplacesTheFile(): void
    {
        self::assertTrue(AtomicFile::write($this->directory->path . '/state', 'first'));
        self::assertTrue(AtomicFile::write($this->directory->path . '/state', 'second'));

        self::assertSame('second', file_get_contents($this->directory->path . '/state'));
        self::assertSame(['state'], $this->directory->entries());
    }

    public function testReturnsFalseAndLeavesNothingBehindWhenTheRenameFails(): void
    {
        mkdir($this->directory->path . '/state');

        self::assertFalse(AtomicFile::write($this->directory->path . '/state', 'contents'));

        self::assertSame(['state'], $this->directory->entries());
    }

    public function testReturnsFalseWhenTheDirectoryDoesNotExist(): void
    {
        self::assertFalse(AtomicFile::write($this->directory->path . '/missing/state', 'contents'));

        self::assertSame([], $this->directory->entries());
    }

    public function testExclusiveCreateRefusesAnExistingTemporaryPath(): void
    {
        $temporary = $this->directory->path . '/state.' . bin2hex(random_bytes(8)) . '.tmp';
        $trap = $this->directory->path . '/trap';
        file_put_contents($trap, 'secret');
        symlink($trap, $temporary);

        $handle = @fopen($temporary, 'xb');

        self::assertFalse($handle);
        self::assertSame('secret', file_get_contents($trap));
    }
}
