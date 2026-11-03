<?php

declare(strict_types=1);

namespace Tracewick\Encoder\Pprof;

/**
 * The string table of a pprof profile: every string is stored once and referenced by index,
 * and index 0 is always the empty string, as profile.proto requires.
 *
 * @internal
 */
final class StringTable
{
    /** @var list<string> */
    private array $strings = [''];

    /**
     * PHP turns numeric string keys into ints, so the strings themselves are kept in a list.
     *
     * @var array<array-key, int>
     */
    private array $indexes = ['' => 0];

    public function index(string $value): int
    {
        if (!isset($this->indexes[$value])) {
            $this->indexes[$value] = \count($this->strings);
            $this->strings[] = $value;
        }

        return $this->indexes[$value];
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->strings;
    }
}
