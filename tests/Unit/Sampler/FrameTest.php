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
        yield 'closure before PHP 8.4' => [new Frame('{closure}', null, '/app/src/routes.php', 12, 10), '{closure:/app/src/routes.php:10}'];
        yield 'closure bound to a class' => [new Frame('{closure}', 'App\Kernel', '/app/src/Kernel.php', 30, 28), 'App\Kernel::{closure:/app/src/Kernel.php:28}'];
        yield 'closure without definition line' => [new Frame('{closure}', null, '/app/src/routes.php', 12), '{closure}'];
        yield 'closure without file' => [new Frame('{closure}', null, null, 0, 7), '{closure::7}'];
        yield 'closure named by PHP 8.4' => [new Frame('{closure:/app/src/routes.php:10}', null, '/app/src/routes.php', 12, 10), '{closure:/app/src/routes.php:10}'];
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
