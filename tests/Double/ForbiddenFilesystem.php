<?php

declare(strict_types=1);

namespace Patchline\Tests\Double;

use DateTimeImmutable;
use LogicException;
use Patchline\Infrastructure\Filesystem;

/** Fails the test on any use: proves code under test never touches the filesystem. */
final class ForbiddenFilesystem implements Filesystem
{
    public function exists(string $path): bool
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function isFile(string $path): bool
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function isDirectory(string $path): bool
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function read(string $path): string
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function writeAtomic(string $path, string $contents): void
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function replaceAtomic(string $path, string $contents): void
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function rename(string $from, string $to): void
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function delete(string $path): void
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function makeDirectory(string $path): void
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function listFiles(string $directory, string $suffix): array
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function copy(string $from, string $to): void
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function temporaryDirectory(): string
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function removeRecursive(string $path): void
    {
        throw $this->forbidden(__FUNCTION__);
    }

    public function modifiedAt(string $path): DateTimeImmutable
    {
        throw $this->forbidden(__FUNCTION__);
    }

    private function forbidden(string $method): LogicException
    {
        return new LogicException('Unexpected filesystem call: ' . $method);
    }
}
