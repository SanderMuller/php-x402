<?php

declare(strict_types=1);

namespace X402\Cli;

use Closure;

/**
 * Tiny stdout/stderr abstraction so command classes are testable without
 * intercepting global PHP output. Production callers use `Output::default()`
 * (writes to STDOUT/STDERR); tests pass closures that record the writes.
 */
final readonly class Output
{
    /**
     * @param  Closure(string): void  $stdoutWriter
     * @param  Closure(string): void  $stderrWriter
     */
    public function __construct(
        private Closure $stdoutWriter,
        private Closure $stderrWriter,
    ) {}

    public static function default(): self
    {
        return new self(
            stdoutWriter: static function (string $s): void {
                fwrite(STDOUT, $s);
            },
            stderrWriter: static function (string $s): void {
                fwrite(STDERR, $s);
            },
        );
    }

    public function stdout(string $line): void
    {
        ($this->stdoutWriter)($line);
    }

    public function stderr(string $line): void
    {
        ($this->stderrWriter)($line);
    }
}
