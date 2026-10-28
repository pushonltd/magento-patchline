<?php

declare(strict_types=1);

namespace Patchline\Infrastructure;

use DateTimeImmutable;

interface Filesystem
{
    public function exists(string $path): bool;

    public function isFile(string $path): bool;

    public function isDirectory(string $path): bool;

    public function read(string $path): string;

    /** Writes via `<path>.part`; refuses to overwrite an existing target. */
    public function writeAtomic(string $path, string $contents): void;

    /** Writes via `<path>.part` and renames over an existing target (cache files only). */
    public function replaceAtomic(string $path, string $contents): void;

    /** Refuses to overwrite an existing target. */
    public function rename(string $from, string $to): void;

    public function delete(string $path): void;

    public function makeDirectory(string $path): void;

    /**
     * Regular files directly in `$directory` whose name ends with `$suffix`, in byte order.
     *
     * @return list<string> full paths
     */
    public function listFiles(string $directory, string $suffix): array;

    /** Copies a file, creating parent directories. */
    public function copy(string $from, string $to): void;

    public function temporaryDirectory(): string;

    public function removeRecursive(string $path): void;

    public function modifiedAt(string $path): DateTimeImmutable;
}
