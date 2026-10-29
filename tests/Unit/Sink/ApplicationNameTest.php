<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Sink;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Sink\ApplicationName;

#[CoversClass(ApplicationName::class)]
final class ApplicationNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function valid(): iterable
    {
        yield 'letters' => ['blog'];
        yield 'leading digit' => ['1blog'];
        yield 'dots, dashes and underscores' => ['shop.api-v2_eu'];
        yield 'single character' => ['a'];
        yield '256 characters' => [str_repeat('a', 256)];
    }

    #[DataProvider('valid')]
    public function testAcceptsValidNames(string $name): void
    {
        $this->expectNotToPerformAssertions();

        ApplicationName::assertValid($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['my app'];
        yield 'leading dot' => ['.blog'];
        yield 'leading dash' => ['-blog'];
        yield 'braces' => ['blog{env=prod}'];
        yield 'trailing newline' => ["blog\n"];
        yield '257 characters' => [str_repeat('a', 257)];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidNames(string $name): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The application name must be 1 to 256 letters, digits, dots, underscores or dashes, starting with a letter or digit');

        ApplicationName::assertValid($name);
    }
}
