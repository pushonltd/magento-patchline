<?php

declare(strict_types=1);

namespace Patchline\Tests\Double;

use RuntimeException;

final class TempDirectory
{
    private function __construct(private readonly string $path)
    {
    }

    public static function create(): self
    {
        $path = sys_get_temp_dir() . '/patchline-test-' . bin2hex(random_bytes(6));
        if (!mkdir($path, 0777, true)) {
            throw new RuntimeException('Cannot create ' . $path);
        }

        return new self($path);
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->path : $this->path . '/' . $relative;
    }

    public function remove(): void
    {
        self::removeTree($this->path);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
