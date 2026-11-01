<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sampler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tracewick\Sampler\Frame;

#[CoversClass(Frame::class)]
final class FrameTest extends TestCase
{
    #[DataProvider('names')]
    public function testName(Frame $frame, string $expected): void
    {
        self::assertSame($expected, $frame->name());
    }

    /**
     * @return iterable<string, array{Frame, string}>
     */
    public static function names(): iterable
    {
        yield 'function' => [new Frame('strlen'), 'strlen'];
        yield 'method' => [new Frame('handle', 'App\Kernel', '/app/src/Kernel.php', 42), 'App\Kernel::handle'];
        yield 'top-level code' => [new Frame(null, null, '/app/public/index.php', 5), '{main}'];
        yield 'empty function name' => [new Frame('', 'App\Kernel'), '{main}'];
        yield 'empty class name' => [new Frame('helper', ''), 'helper'];

        yield 'pre-8.4 top-level closure' => [
            new Frame('{closure}', null, '/app/src/routes.php', 12, 10),
            '{closure:/app/src/routes.php:10}',
        ];
        yield 'pre-8.4 bound closure without method' => [
            new Frame('{closure}', 'App\Kernel', '/app/src/Kernel.php', 30, 28),
            'App\Kernel::{closure:28}',
        ];
        yield 'pre-8.4 closure without definition line' => [
            new Frame('{closure}', null, '/app/src/routes.php', 12),
            '{closure}',
        ];
        yield 'pre-8.4 bound without definition line' => [
            new Frame('{closure}', 'App\Kernel', '/app/src/Kernel.php', 30),
            'App\Kernel::{closure}',
        ];
        yield 'pre-8.4 closure without file' => [
            new Frame('{closure}', null, null, 0, 7),
            '{closure::7}',
        ];
        yield 'stripped relative path already on frame' => [
            new Frame('{closure}', null, 'src/routes.php', 12, 10),
            '{closure:src/routes.php:10}',
        ];

        yield 'PHP 8.4 method closure' => [
            new Frame('{closure:App\Foo::bar():12}', 'App\Foo', '/app/src/Foo.php', 15, 12),
            'App\Foo::bar{closure:12}',
        ];
        yield 'PHP 8.4 method closure ignores redundant class prefix' => [
            new Frame('{closure:App\Foo::bar():12}', null, '/app/src/Foo.php', 15, 12),
            'App\Foo::bar{closure:12}',
        ];
        yield 'PHP 8.4 function closure' => [
            new Frame('{closure:helper():9}', null, '/app/helpers.php', 11, 9),
            'helper{closure:9}',
        ];
        yield 'PHP 8.4 path closure' => [
            new Frame('{closure:/app/src/routes.php:10}', null, '/app/src/routes.php', 12, 10),
            '{closure:/app/src/routes.php:10}',
        ];
        yield 'PHP 8.4 path closure with class falls back to class form' => [
            new Frame('{closure:/app/src/Kernel.php:28}', 'App\Kernel', '/app/src/Kernel.php', 30, 28),
            'App\Kernel::{closure:28}',
        ];
        yield 'PHP 8.4 relative path after strip' => [
            new Frame('{closure:src/routes.php:10}', null, 'src/routes.php', 12, 10),
            '{closure:src/routes.php:10}',
        ];
        yield 'malformed closure without line colon' => [
            new Frame('{closure:nocolon}', null, '/app/x.php', 1),
            '{closure:nocolon}',
        ];
        yield 'malformed closure with non-numeric line' => [
            new Frame('{closure:scope:abc}', null, '/app/x.php', 1),
            '{closure:scope:abc}',
        ];
    }

    public function testDefaults(): void
    {
        $frame = new Frame('main');

        self::assertNull($frame->class);
        self::assertNull($frame->file);
        self::assertSame(0, $frame->line);
        self::assertNull($frame->closureLine);
    }
}
