<?php

declare(strict_types=1);

namespace Patchline\Tests\Double;

use DateTimeImmutable;
use Patchline\Infrastructure\Filesystem;

/**
 * Delegates to another filesystem and remembers every temporary directory it handed out.
 */
final class TemporaryDirectoryTrackingFilesystem implements Filesystem
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    public function __construct(private readonly Filesystem $inner)
    {
    }

    /**
     * @return list<string>
     */
    public function temporaryDirectories(): array
    {
        return $this->temporaryDirectories;
    }

    public function temporaryDirectory(): string
    {
        return $this->temporaryDirectories[] = $this->inner->temporaryDirectory();
    }

    public function exists(string $path): bool
    {
        return $this->inner->exists($path);
    }

    public function isFile(string $path): bool
    {
        return $this->inner->isFile($path);
    }

    public function isDirectory(string $path): bool
    {
        return $this->inner->isDirectory($path);
    }

    public function read(string $path): string
    {
        return $this->inner->read($path);
    }

    public function writeAtomic(string $path, string $contents): void
    {
        $this->inner->writeAtomic($path, $contents);
    }

    public function replaceAtomic(string $path, string $contents): void
    {
        $this->inner->replaceAtomic($path, $contents);
    }

    public function rename(string $from, string $to): void
    {
        $this->inner->rename($from, $to);
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    public function makeDirectory(string $path): void
    {
        $this->inner->makeDirectory($path);
    }

    public function listFiles(string $directory, string $suffix): array
    {
        return $this->inner->listFiles($directory, $suffix);
    }

    public function copy(string $from, string $to): void
    {
        $this->inner->copy($from, $to);
    }

    public function removeRecursive(string $path): void
    {
        $this->inner->removeRecursive($path);
    }

    public function modifiedAt(string $path): DateTimeImmutable
    {
        return $this->inner->modifiedAt($path);
    }
}
