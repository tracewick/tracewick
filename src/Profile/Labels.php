<?php

declare(strict_types=1);

namespace Tracewick\Profile;

use Tracewick\Exception\LogicException;

/**
 * Bounded set of key-value labels attached to a profile, such as `service` or `env`.
 *
 * Keys are kept sorted so that equal label sets produce identical output in every encoder.
 * Invalid keys, oversized values and too many labels throw: label sets are built from
 * configuration, where a clear error is better than labels silently missing in the backend.
 *
 * @implements \IteratorAggregate<string, string>
 */
final readonly class Labels implements \IteratorAggregate, \Countable
{
    public const MAX_LABELS = 64;

    public const MAX_KEY_LENGTH = 128;

    public const MAX_VALUE_LENGTH = 1024;

    /**
     * Letters, digits, underscores and dots, not starting with a digit or a dot. This is the
     * common subset accepted by Prometheus-style label backends once dots are mapped.
     */
    private const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.]*$/D';

    /**
     * @var array<string, string>
     */
    private array $values;

    /**
     * @param array<string, string> $values
     */
    public function __construct(array $values = [])
    {
        if (\count($values) > self::MAX_LABELS) {
            throw new LogicException(\sprintf('A profile accepts at most %d labels, got %d.', self::MAX_LABELS, \count($values)));
        }

        foreach ($values as $key => $value) {
            $this->validate((string) $key, $value);
        }

        ksort($values, \SORT_STRING);
        $this->values = $values;
    }

    /**
     * Returns a copy with the label set, replacing an existing value for the same key.
     */
    public function with(string $key, string $value): self
    {
        return new self([$key => $value] + $this->values);
    }

    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    /**
     * @return array<string, string> labels sorted by key
     */
    public function toArray(): array
    {
        return $this->values;
    }

    public function isEmpty(): bool
    {
        return [] === $this->values;
    }

    public function count(): int
    {
        return \count($this->values);
    }

    /**
     * @return \ArrayIterator<string, string>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->values);
    }

    private function validate(string $key, string $value): void
    {
        if (\strlen($key) > self::MAX_KEY_LENGTH || 1 !== preg_match(self::KEY_PATTERN, $key)) {
            throw new LogicException(\sprintf(
                'Label key "%s" is invalid: use at most %d letters, digits, underscores and dots, not starting with a digit or a dot.',
                $key,
                self::MAX_KEY_LENGTH,
            ));
        }

        if (\strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new LogicException(\sprintf('Value of label "%s" exceeds %d bytes.', $key, self::MAX_VALUE_LENGTH));
        }
    }
}
