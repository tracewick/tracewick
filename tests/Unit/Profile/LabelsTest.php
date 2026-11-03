<?php

declare(strict_types=1);

namespace Tracewick\Tests\Unit\Profile;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Tracewick\Exception\LogicException;
use Tracewick\Profile\Labels;

#[CoversClass(Labels::class)]
#[UsesClass(LogicException::class)]
final class LabelsTest extends TestCase
{
    public function testEmptyByDefault(): void
    {
        $labels = new Labels();

        self::assertTrue($labels->isEmpty());
        self::assertCount(0, $labels);
        self::assertSame([], $labels->toArray());
    }

    public function testSortsByKey(): void
    {
        $labels = new Labels(['service' => 'shop', 'env' => 'prod', 'Region' => 'eu']);

        self::assertSame(['Region' => 'eu', 'env' => 'prod', 'service' => 'shop'], $labels->toArray());
        self::assertSame(['Region' => 'eu', 'env' => 'prod', 'service' => 'shop'], iterator_to_array($labels));
        self::assertCount(3, $labels);
        self::assertFalse($labels->isEmpty());
    }

    public function testWithAddsOrReplacesWithoutChangingTheOriginal(): void
    {
        $original = new Labels(['env' => 'prod']);

        $added = $original->with('service', 'shop');
        $replaced = $added->with('env', 'staging');

        self::assertSame(['env' => 'prod'], $original->toArray());
        self::assertSame(['env' => 'prod', 'service' => 'shop'], $added->toArray());
        self::assertSame(['env' => 'staging', 'service' => 'shop'], $replaced->toArray());
    }

    public function testGet(): void
    {
        $labels = new Labels(['env' => 'prod']);

        self::assertSame('prod', $labels->get('env'));
        self::assertNull($labels->get('service'));
    }

    public function testAcceptsBoundaryValues(): void
    {
        $values = [];
        for ($i = 0; $i < Labels::MAX_LABELS - 1; ++$i) {
            $values['k' . $i] = '';
        }

        $values[str_repeat('k', Labels::MAX_KEY_LENGTH)] = str_repeat('v', Labels::MAX_VALUE_LENGTH);

        self::assertCount(Labels::MAX_LABELS, new Labels($values));
    }

    #[DataProvider('invalidKeys')]
    public function testRejectsInvalidKey(string $key): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(\sprintf('Label key "%s" is invalid', $key));

        new Labels([$key => 'value']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with a digit' => ['1env'];
        yield 'starts with a dot' => ['.env'];
        yield 'contains a dash' => ['service-name'];
        yield 'contains a space' => ['service name'];
        yield 'trailing newline' => ["env\n"];
        yield 'too long' => [str_repeat('k', Labels::MAX_KEY_LENGTH + 1)];
    }

    public function testAcceptsDotsAndUnderscores(): void
    {
        self::assertSame('1', (new Labels(['_service.version_2' => '1']))->get('_service.version_2'));
    }

    public function testRejectsOversizedValue(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Value of label "env" exceeds 1024 bytes.');

        new Labels(['env' => str_repeat('v', Labels::MAX_VALUE_LENGTH + 1)]);
    }

    public function testRejectsTooManyLabels(): void
    {
        $values = [];
        for ($i = 0; $i <= Labels::MAX_LABELS; ++$i) {
            $values['k' . $i] = 'v';
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A profile accepts at most 64 labels, got 65.');

        new Labels($values);
    }

    public function testWithValidatesToo(): void
    {
        $this->expectException(LogicException::class);

        (new Labels())->with('bad key', 'value');
    }
}
