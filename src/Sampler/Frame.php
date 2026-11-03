<?php

declare(strict_types=1);

namespace Tracewick\Sampler;

/**
 * One frame of a sampled call stack.
 */
final readonly class Frame
{
    /**
     * Name used for code that runs outside of any function, such as the body of a script.
     */
    public const MAIN = '{main}';

    /**
     * @param ?string $function    function or method name; null for top-level code
     * @param ?string $class       class of a method, null for plain functions and closures
     * @param ?string $file        file that defines the code, null for internal functions
     * @param int     $line        line being executed when the sample was taken, 0 if unknown
     * @param ?int    $closureLine line on which a closure was defined, null for named functions
     */
    public function __construct(
        public ?string $function,
        public ?string $class = null,
        public ?string $file = null,
        public int $line = 0,
        public ?int $closureLine = null,
    ) {}

    /**
     * Aggregation name independent of PHP version and (when paths were stripped) deploy prefix.
     *
     * Closures use a Tracewick canon:
     * - `Class::method{closure:line}` / `function{closure:line}` when the enclosing scope is known
     *   (PHP 8.4-shaped Excimer names, or reconstructed from fields);
     * - `Class::{closure:line}` when only the class is known (pre-8.4 bound closures);
     * - `{closure:file:line}` for top-level closures.
     */
    public function name(): string
    {
        $function = $this->function;
        if (null === $function || '' === $function) {
            return self::MAIN;
        }

        $canon = $this->canonicalClosure($function);
        if (null !== $canon) {
            return $canon;
        }

        if (null === $this->class || '' === $this->class) {
            return $function;
        }

        return $this->class . '::' . $function;
    }

    /**
     * @return ?string null when $function is not a closure name
     */
    private function canonicalClosure(string $function): ?string
    {
        if ('{closure}' === $function) {
            return $this->closureFromFields();
        }

        if (!str_starts_with($function, '{closure:') || !str_ends_with($function, '}')) {
            return null;
        }

        $inner = substr($function, \strlen('{closure:'), -1);
        $colon = strrpos($inner, ':');
        if (false === $colon) {
            return $function;
        }

        $scope = substr($inner, 0, $colon);
        $linePart = substr($inner, $colon + 1);
        if ('' === $linePart || \strlen($linePart) !== strspn($linePart, '0123456789')) {
            return $function;
        }

        $line = (int) $linePart;

        // PHP 8.4 method: Class::method():12
        if (str_ends_with($scope, '()') && str_contains($scope, '::')) {
            $withoutParens = substr($scope, 0, -2);

            return $withoutParens . '{closure:' . $line . '}';
        }

        // PHP 8.4 function: foo():12
        if (str_ends_with($scope, '()') && !str_contains($scope, '::')) {
            $withoutParens = substr($scope, 0, -2);

            return $withoutParens . '{closure:' . $line . '}';
        }

        // Path form: /abs/file.php:12 (path may already be stripped by the converter)
        if (null !== $this->class && '' !== $this->class) {
            return $this->class . '::{closure:' . $line . '}';
        }

        return '{closure:' . $scope . ':' . $line . '}';
    }

    private function closureFromFields(): string
    {
        $line = $this->closureLine;
        if (null === $line) {
            if (null !== $this->class && '' !== $this->class) {
                return $this->class . '::{closure}';
            }

            return '{closure}';
        }

        if (null !== $this->class && '' !== $this->class) {
            return $this->class . '::{closure:' . $line . '}';
        }

        return '{closure:' . ($this->file ?? '') . ':' . $line . '}';
    }
}
