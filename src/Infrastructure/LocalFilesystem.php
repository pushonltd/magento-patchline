<?php

declare(strict_types=1);

namespace Patchline\Infrastructure;

use DateTimeImmutable;
use Patchline\Exception\RuntimeFailure;

final class LocalFilesystem implements Filesystem
{
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function isFile(string $path): bool
    {
        return is_file($path);
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function read(string $path): string
    {
        $contents = is_file($path) ? @file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeFailure(sprintf('Cannot read %s. Check that the file exists and is readable.', $path));
        }

        return $contents;
    }

    public function writeAtomic(string $path, string $contents): void
    {
        $this->write($path, $contents, false);
    }

    public function replaceAtomic(string $path, string $contents): void
    {
        $this->write($path, $contents, true);
    }

    public function rename(string $from, string $to): void
    {
        if (file_exists($to)) {
            throw new RuntimeFailure(sprintf('Refusing to overwrite %s.', $to));
        }
        if (!@rename($from, $to)) {
            throw new RuntimeFailure(sprintf('Cannot move %s to %s.', $from, $to));
        }
    }

    public function delete(string $path): void
    {
        if (!@unlink($path)) {
            throw new RuntimeFailure(sprintf('Cannot delete %s.', $path));
        }
    }

    public function makeDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeFailure(sprintf('Cannot create directory %s.', $path));
        }
    }

    public function listFiles(string $directory, string $suffix): array
    {
        $entries = @scandir($directory, SCANDIR_SORT_NONE);
        if ($entries === false) {
            throw new RuntimeFailure(sprintf('Cannot list %s.', $directory));
        }
        $files = [];
        foreach ($entries as $entry) {
            $path = $directory . '/' . $entry;
            if (!str_ends_with($entry, $suffix) || !is_file($path)) {
                continue;
            }

            $files[] = $path;
        }
        usort($files, strcmp(...));

        return $files;
    }

    public function copy(string $from, string $to): void
    {
        $this->makeDirectory(dirname($to));
        if (!@copy($from, $to)) {
            throw new RuntimeFailure(sprintf('Cannot copy %s to %s.', $from, $to));
        }
    }

    public function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir() . '/patchline-' . bin2hex(random_bytes(8));
        $this->makeDirectory($path);

        return $path;
    }

    public function removeRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            $this->delete($path);

            return;
        }
        $entries = is_dir($path) ? scandir($path) : [];
        foreach ($entries !== false ? $entries : [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeRecursive($path . '/' . $entry);
        }
        if (is_dir($path) && !@rmdir($path)) {
            throw new RuntimeFailure(sprintf('Cannot remove directory %s.', $path));
        }
    }

    public function modifiedAt(string $path): DateTimeImmutable
    {
        $time = @filemtime($path);
        if ($time === false) {
            throw new RuntimeFailure(sprintf('Cannot read modification time of %s.', $path));
        }

        return (new DateTimeImmutable())->setTimestamp($time);
    }

    private function write(string $path, string $contents, bool $overwrite): void
    {
        $this->makeDirectory(dirname($path));
        $part = $path . '.part';
        $handle = @fopen($part, 'x');
        if ($handle === false) {
            throw new RuntimeFailure(sprintf('Cannot create %s. Remove it if a previous run left it behind.', $part));
        }
        $written = fwrite($handle, $contents);
        $closed = fclose($handle);
        if ($written !== strlen($contents) || !$closed) {
            @unlink($part);

            throw new RuntimeFailure(sprintf('Cannot write %s. Check free disk space.', $path));
        }
        if (!$overwrite && file_exists($path)) {
            @unlink($part);

            throw new RuntimeFailure(sprintf('Refusing to overwrite %s.', $path));
        }
        if (!@rename($part, $path)) {
            @unlink($part);

            throw new RuntimeFailure(sprintf('Cannot move %s into place.', $path));
        }
    }
}
