<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Queue;

use Patchline\Detection\DiffParser;
use Patchline\Detection\DiffSection;
use Patchline\Detection\FilteredDiff;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Installation\Edition;
use Patchline\Installation\Installation;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueEntryDiff;
use Patchline\Queue\QueueOrigin;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QueueEntryDiff::class)]
#[UsesClass(DiffParser::class)]
#[UsesClass(DiffSection::class)]
#[UsesClass(FilteredDiff::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(Installation::class)]
#[UsesClass(QueueEntry::class)]
final class QueueEntryDiffTest extends TestCase
{
    private TempDirectory $store;

    protected function setUp(): void
    {
        $this->store = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        $this->store->remove();
    }

    public function test_it_reads_the_entry_relative_to_the_store_root(): void
    {
        $this->write('m2-hotfixes/one.patch', $this->section('vendor/magento/module-a/a.php', 'diff -Nuar'));

        $text = $this->prepare('m2-hotfixes/one.patch', []);

        self::assertSame($this->section('vendor/magento/module-a/a.php', 'diff -Nuar'), $text);
    }

    public function test_it_rewrites_diff_git_headers_to_diff_nuar_like_ece_patches(): void
    {
        $content = $this->section('vendor/magento/module-a/a.php', 'diff --git')
            . $this->section('vendor/magento/module-b/b.php', 'diff --git');
        $this->write('p.patch', $content);

        $text = $this->prepare('p.patch', []);

        self::assertSame(
            $this->section('vendor/magento/module-a/a.php', 'diff -Nuar')
            . $this->section('vendor/magento/module-b/b.php', 'diff -Nuar'),
            $text,
        );
    }

    public function test_it_drops_sections_for_replaced_packages(): void
    {
        $this->write('p.patch', $this->section('vendor/magento/module-a/a.php', 'diff --git')
            . $this->section('vendor/magento/module-b/b.php', 'diff --git'));

        $text = $this->prepare('p.patch', ['magento/module-a']);

        self::assertSame($this->section('vendor/magento/module-b/b.php', 'diff -Nuar'), $text);
    }

    public function test_it_returns_an_empty_diff_when_every_section_targets_a_replaced_package(): void
    {
        $this->write('p.patch', $this->section('vendor/magento/module-a/a.php', 'diff --git'));

        $text = $this->prepare('p.patch', ['magento/module-a']);

        self::assertSame('', $text);
    }

    /** @param list<string> $replaced */
    private function prepare(string $path, array $replaced): string
    {
        $installation = new Installation(
            $this->store->path(),
            [],
            $replaced,
            Edition::OpenSource,
            '2.4.9',
            null,
            false,
        );
        $entry = new QueueEntry(1, QueueOrigin::Hotfix, null, $path, 'sha');

        return (new QueueEntryDiff(new LocalFilesystem(), new DiffParser()))->prepare($installation, $entry);
    }

    private function section(string $file, string $command): string
    {
        return $command . ' a/' . $file . ' b/' . $file . "\n--- a/" . $file . "\n+++ b/" . $file
            . "\n@@ -1 +1 @@\n-old\n+new\n";
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->store->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }
}
