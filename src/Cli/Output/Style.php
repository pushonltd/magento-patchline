<?php

declare(strict_types=1);

namespace Patchline\Cli\Output;

/**
 * ANSI styling, decided by the caller: enable only on a TTY, with NO_COLOR unset and no --no-ansi.
 */
final class Style
{
    private function __construct(private readonly bool $enabled)
    {
    }

    public static function enabled(): self
    {
        return new self(true);
    }

    public static function disabled(): self
    {
        return new self(false);
    }

    public function bold(string $text): string
    {
        return $this->wrap($text, '1');
    }

    public function dim(string $text): string
    {
        return $this->wrap($text, '2');
    }

    public function red(string $text): string
    {
        return $this->wrap($text, '31');
    }

    public function green(string $text): string
    {
        return $this->wrap($text, '32');
    }

    public function yellow(string $text): string
    {
        return $this->wrap($text, '33');
    }

    private function wrap(string $text, string $code): string
    {
        return $this->enabled ? "\033[" . $code . 'm' . $text . "\033[0m" : $text;
    }
}
