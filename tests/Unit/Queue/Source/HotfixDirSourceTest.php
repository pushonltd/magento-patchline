<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Queue\Source;

use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use Patchline\Queue\Source\HotfixDirSource;
use Patchline\Queue\Source\SourceResult;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HotfixDirSource::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(SourceResult::class)]
final class HotfixDirSourceTest extends TestCase
{
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
        mkdir($this->dir->path('m2-hotfixes/sub'), 0777, true);
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function test_it_queues_m2_hotfixes_patch_files_in_byte_order(): void
    {
        file_put_contents($this->dir->path('m2-hotfixes/b.patch'), 'b');
        file_put_contents($this->dir->path('m2-hotfixes/Z.patch'), 'Z');
        file_put_contents($this->dir->path('m2-hotfixes/a.patch'), 'a');

        $result = $this->collect();

        self::assertSame(
            ['m2-hotfixes/Z.patch', 'm2-hotfixes/a.patch', 'm2-hotfixes/b.patch'],
            array_map(static fn ($e) => $e->path, $result->entries),
        );
        self::assertSame(QueueOrigin::Hotfix, $result->entries[0]->origin);
        self::assertNull($result->entries[0]->title);
        self::assertSame(hash('sha256', 'Z'), $result->entries[0]->sha256);
    }

    public function test_it_ignores_files_in_m2_hotfixes_without_the_patch_extension_and_in_subdirectories(): void
    {
        file_put_contents($this->dir->path('m2-hotfixes/a.patch'), 'a');
        file_put_contents($this->dir->path('m2-hotfixes/notes.txt'), 'x');
        file_put_contents($this->dir->path('m2-hotfixes/a.patch.bak'), 'x');
        file_put_contents($this->dir->path('m2-hotfixes/sub/deep.patch'), 'x');

        $result = $this->collect();

        self::assertSame(['m2-hotfixes/a.patch'], array_map(static fn ($e) => $e->path, $result->entries));
    }

    public function test_it_returns_nothing_when_there_is_no_m2_hotfixes_directory(): void
    {
        $this->dir->remove();
        $this->dir = TempDirectory::create();

        self::assertSame([], $this->collect()->entries);
    }

    private function collect(): \Patchline\Queue\Source\SourceResult
    {
        return (new HotfixDirSource(new LocalFilesystem()))->collect($this->dir->path());
    }
}
