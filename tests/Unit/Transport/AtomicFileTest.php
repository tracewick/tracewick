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
        AtomicFile::write($this->directory->path . '/state', 'first');
        AtomicFile::write($this->directory->path . '/state', 'second');

        self::assertSame('second', file_get_contents($this->directory->path . '/state'));
        self::assertSame(['state'], $this->directory->entries());
    }

    public function testLeavesNothingBehindWhenTheRenameFails(): void
    {
        mkdir($this->directory->path . '/state');

        AtomicFile::write($this->directory->path . '/state', 'contents');

        self::assertSame(['state'], $this->directory->entries());
    }

    public function testIgnoresADirectoryThatDoesNotExist(): void
    {
        AtomicFile::write($this->directory->path . '/missing/state', 'contents');

        self::assertSame([], $this->directory->entries());
    }
}
