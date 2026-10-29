<?php

declare(strict_types=1);

namespace Tracewick\Tests\Support;

/**
 * Empty directory under the system temporary directory, removed with its contents on demand.
 */
final readonly class TemporaryDirectory
{
    public string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/tracewick-test-' . bin2hex(random_bytes(8));
        mkdir($this->path);
    }

    /**
     * Names of the entries in the directory, sorted, without "." and "..".
     *
     * @return list<string>
     */
    public function entries(string $subdirectory = ''): array
    {
        return $this->list($this->path . $subdirectory);
    }

    public function remove(): void
    {
        $this->removeTree($this->path);
    }

    /**
     * @return list<string>
     */
    private function list(string $path): array
    {
        $entries = scandir($path);

        return array_values(array_diff(false === $entries ? [] : $entries, ['.', '..']));
    }

    private function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach ($this->list($path) as $entry) {
                $this->removeTree($path . '/' . $entry);
            }

            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
