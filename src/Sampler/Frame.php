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
     * Human-readable name that identifies the function, independent of the line being executed.
     *
     * Closures are named `{closure:file:line}` on every PHP version, matching the native naming
     * of PHP 8.4, so that profiles from different PHP versions aggregate the same way.
     */
    public function name(): string
    {
        $function = $this->function;
        if (null === $function || '' === $function) {
            return self::MAIN;
        }

        if ('{closure}' === $function && null !== $this->closureLine) {
            $function = \sprintf('{closure:%s:%d}', $this->file ?? '', $this->closureLine);
        }

        if (null === $this->class || '' === $this->class) {
            return $function;
        }

        return $this->class . '::' . $function;
    }
}
