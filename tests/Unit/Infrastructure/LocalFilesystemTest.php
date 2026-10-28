<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Infrastructure;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalFilesystem::class)]
final class LocalFilesystemTest extends TestCase
{
    private TempDirectory $dir;
    private LocalFilesystem $fs;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
        $this->fs = new LocalFilesystem();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function test_it_writes_a_file_atomically_and_leaves_no_part_file_behind(): void
    {
        $target = $this->dir->path('m2-hotfixes/a.patch');

        $this->fs->writeAtomic($target, 'content');

        self::assertSame('content', file_get_contents($target));
        self::assertFileDoesNotExist($target . '.part');
    }

    public function test_it_refuses_to_overwrite_an_existing_file_on_atomic_write(): void
    {
        $target = $this->dir->path('a.patch');
        file_put_contents($target, 'old');

        try {
            $this->fs->writeAtomic($target, 'new');
            self::fail('Expected RuntimeFailure');
        } catch (RuntimeFailure) {
            self::assertSame('old', file_get_contents($target));
            self::assertFileDoesNotExist($target . '.part');
        }
    }

    public function test_it_replaces_an_existing_cache_file_atomically(): void
    {
        $target = $this->dir->path('cache.json');
        file_put_contents($target, 'old');

        $this->fs->replaceAtomic($target, 'new');

        self::assertSame('new', file_get_contents($target));
        self::assertFileDoesNotExist($target . '.part');
    }

    public function test_it_lists_files_in_a_directory_in_byte_order(): void
    {
        foreach (['b.patch', 'Z.patch', 'a.patch', 'c.txt'] as $name) {
            file_put_contents($this->dir->path($name), '');
        }
        mkdir($this->dir->path('d.patch'));

        $files = $this->fs->listFiles($this->dir->path(), '.patch');

        self::assertSame(
            [$this->dir->path('Z.patch'), $this->dir->path('a.patch'), $this->dir->path('b.patch')],
            $files,
        );
    }

    public function test_it_copies_renames_and_deletes_files(): void
    {
        file_put_contents($this->dir->path('a.txt'), 'x');

        $this->fs->copy($this->dir->path('a.txt'), $this->dir->path('deep/er/b.txt'));
        $this->fs->rename($this->dir->path('deep/er/b.txt'), $this->dir->path('c.txt'));
        $this->fs->delete($this->dir->path('a.txt'));

        self::assertFalse($this->fs->exists($this->dir->path('a.txt')));
        self::assertTrue($this->fs->isFile($this->dir->path('c.txt')));
        self::assertTrue($this->fs->isDirectory($this->dir->path('deep/er')));
        self::assertSame('x', $this->fs->read($this->dir->path('c.txt')));
        self::assertGreaterThan(0, $this->fs->modifiedAt($this->dir->path('c.txt'))->getTimestamp());
    }

    public function test_it_refuses_to_rename_over_an_existing_file(): void
    {
        file_put_contents($this->dir->path('a'), 'a');
        file_put_contents($this->dir->path('b'), 'b');

        $this->expectException(RuntimeFailure::class);

        $this->fs->rename($this->dir->path('a'), $this->dir->path('b'));
    }

    public function test_it_fails_reading_a_missing_file(): void
    {
        $this->expectException(RuntimeFailure::class);

        $this->fs->read($this->dir->path('missing'));
    }

    public function test_it_creates_and_removes_a_temporary_directory(): void
    {
        $tmp = $this->fs->temporaryDirectory();
        file_put_contents($tmp . '/f', 'x');
        $this->fs->makeDirectory($tmp . '/sub/dir');

        $this->fs->removeRecursive($tmp);

        self::assertDirectoryDoesNotExist($tmp);
    }

    public function test_it_names_the_existing_target_when_it_refuses_to_overwrite_on_atomic_write(): void
    {
        $target = $this->dir->path('a.patch');
        file_put_contents($target, 'old');

        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('Refusing to overwrite ' . $target . '.');

        $this->fs->writeAtomic($target, 'new');
    }

    public function test_it_does_not_write_over_a_part_file_a_previous_run_left_behind(): void
    {
        $target = $this->dir->path('a.patch');
        file_put_contents($target . '.part', 'half');

        try {
            $this->fs->writeAtomic($target, 'new');
            self::fail('Expected RuntimeFailure');
        } catch (RuntimeFailure $e) {
            self::assertSame(
                'Cannot create ' . $target . '.part. Remove it if a previous run left it behind.',
                $e->getMessage(),
            );
        }
        self::assertSame('half', file_get_contents($target . '.part'));
        self::assertFileDoesNotExist($target);
    }

    public function test_it_reports_when_a_written_file_cannot_be_moved_into_place(): void
    {
        $target = $this->dir->path('occupied');
        mkdir($target);

        try {
            $this->fs->replaceAtomic($target, 'new');
            self::fail('Expected RuntimeFailure');
        } catch (RuntimeFailure $e) {
            self::assertSame('Cannot move ' . $target . ' into place.', $e->getMessage());
        }
        self::assertFileDoesNotExist($target . '.part');
    }

    public function test_it_names_both_paths_when_a_rename_fails(): void
    {
        $from = $this->dir->path('missing');
        $to = $this->dir->path('target');

        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('Cannot move ' . $from . ' to ' . $to . '.');

        $this->fs->rename($from, $to);
    }

    public function test_it_names_the_existing_target_when_it_refuses_to_rename_over_it(): void
    {
        file_put_contents($this->dir->path('a'), 'a');
        file_put_contents($this->dir->path('b'), 'b');

        $this->expectExceptionMessage('Refusing to overwrite ' . $this->dir->path('b') . '.');

        $this->fs->rename($this->dir->path('a'), $this->dir->path('b'));
    }

    public function test_it_names_the_path_when_a_delete_fails(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('Cannot delete ' . $this->dir->path('missing') . '.');

        $this->fs->delete($this->dir->path('missing'));
    }

    public function test_it_creates_nested_directories_with_open_permissions_before_the_umask(): void
    {
        $previous = umask(0);

        try {
            $this->fs->makeDirectory($this->dir->path('a/b/c'));
        } finally {
            umask($previous);
        }

        self::assertDirectoryExists($this->dir->path('a/b/c'));
        self::assertSame(0777, fileperms($this->dir->path('a/b/c')) & 0777);
    }

    public function test_it_accepts_a_directory_that_already_exists(): void
    {
        mkdir($this->dir->path('there'));

        $this->fs->makeDirectory($this->dir->path('there'));

        self::assertDirectoryExists($this->dir->path('there'));
    }

    public function test_it_fails_creating_a_directory_where_a_file_is(): void
    {
        file_put_contents($this->dir->path('file'), 'x');

        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('Cannot create directory ' . $this->dir->path('file/sub') . '.');

        $this->fs->makeDirectory($this->dir->path('file/sub'));
    }

    public function test_it_fails_listing_a_missing_directory(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('Cannot list ' . $this->dir->path('missing') . '.');

        $this->fs->listFiles($this->dir->path('missing'), '.patch');
    }

    public function test_it_copies_into_a_directory_it_creates(): void
    {
        file_put_contents($this->dir->path('src'), 'body');

        $this->fs->copy($this->dir->path('src'), $this->dir->path('deep/er/dst'));

        self::assertSame('body', file_get_contents($this->dir->path('deep/er/dst')));
    }

    public function test_it_names_both_paths_when_a_copy_fails(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage(
            'Cannot copy ' . $this->dir->path('missing') . ' to ' . $this->dir->path('dst') . '.',
        );

        $this->fs->copy($this->dir->path('missing'), $this->dir->path('dst'));
    }

    public function test_it_creates_a_temporary_directory_with_a_random_name_in_the_system_temp_directory(): void
    {
        $first = $this->fs->temporaryDirectory();
        $second = $this->fs->temporaryDirectory();

        try {
            self::assertDirectoryExists($first);
            self::assertNotSame($first, $second);
            self::assertMatchesRegularExpression(
                '~^' . preg_quote(sys_get_temp_dir(), '~') . '/patchline-[0-9a-f]{16}$~',
                $first,
            );
        } finally {
            $this->fs->removeRecursive($first);
            $this->fs->removeRecursive($second);
        }
    }

    public function test_it_removes_a_file_or_a_symlink_without_following_it(): void
    {
        mkdir($this->dir->path('target'));
        file_put_contents($this->dir->path('target/keep'), 'x');
        file_put_contents($this->dir->path('plain'), 'y');
        symlink($this->dir->path('target'), $this->dir->path('link'));

        $this->fs->removeRecursive($this->dir->path('link'));
        $this->fs->removeRecursive($this->dir->path('plain'));

        self::assertFalse(is_link($this->dir->path('link')));
        self::assertFileDoesNotExist($this->dir->path('plain'));
        self::assertFileExists($this->dir->path('target/keep'));
    }

    public function test_it_ignores_a_path_that_does_not_exist_when_removing_recursively(): void
    {
        $this->fs->removeRecursive($this->dir->path('missing'));

        self::assertFileDoesNotExist($this->dir->path('missing'));
    }

    public function test_it_reads_the_modification_time_of_a_file(): void
    {
        file_put_contents($this->dir->path('f'), 'x');
        touch($this->dir->path('f'), 1_700_000_000);

        self::assertSame(1_700_000_000, $this->fs->modifiedAt($this->dir->path('f'))->getTimestamp());
    }

    public function test_it_fails_reading_the_modification_time_of_a_missing_file(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('Cannot read modification time of ' . $this->dir->path('missing') . '.');

        $this->fs->modifiedAt($this->dir->path('missing'));
    }

    public function test_it_reports_what_exists(): void
    {
        file_put_contents($this->dir->path('f'), 'x');

        self::assertTrue($this->fs->exists($this->dir->path('f')));
        self::assertTrue($this->fs->isFile($this->dir->path('f')));
        self::assertFalse($this->fs->isDirectory($this->dir->path('f')));
        self::assertTrue($this->fs->isDirectory($this->dir->path()));
        self::assertFalse($this->fs->isFile($this->dir->path()));
        self::assertFalse($this->fs->exists($this->dir->path('nope')));
    }

    public function test_it_names_the_file_when_reading_fails(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage(
            'Cannot read ' . $this->dir->path('missing') . '. Check that the file exists and is readable.',
        );

        $this->fs->read($this->dir->path('missing'));
    }
}
