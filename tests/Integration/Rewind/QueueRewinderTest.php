<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Rewind;

use FilesystemIterator;
use Patchline\Detection\DiffParser;
use Patchline\Detection\DiffSection;
use Patchline\Detection\FilteredDiff;
use Patchline\Detection\PatchBinary;
use Patchline\Detection\PatchRun;
use Patchline\Detection\SystemPatchBinary;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Infrastructure\SymfonyProcessRunner;
use Patchline\Installation\Edition;
use Patchline\Installation\Installation;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueEntryDiff;
use Patchline\Queue\QueueOrigin;
use Patchline\Rewind\QueueRewinder;
use Patchline\Rewind\RewindOutcome;
use Patchline\Rewind\RewindResult;
use Patchline\Rewind\RewindStep;
use Patchline\Tests\Double\FakePatchBinary;
use Patchline\Tests\Double\PatchCall;
use Patchline\Tests\Double\TempDirectory;
use Patchline\Tests\Double\TemporaryDirectoryTrackingFilesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(QueueRewinder::class)]
#[CoversClass(RewindResult::class)]
#[CoversClass(RewindStep::class)]
#[CoversClass(RewindOutcome::class)]
#[UsesClass(DiffParser::class)]
#[UsesClass(DiffSection::class)]
#[UsesClass(FilteredDiff::class)]
#[UsesClass(PatchRun::class)]
#[UsesClass(SystemPatchBinary::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(Installation::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(QueueEntryDiff::class)]
final class QueueRewinderTest extends TestCase
{
    private const FILE_A = 'vendor/magento/module-a/a.php';
    private const FILE_B = 'vendor/magento/module-b/b.php';
    private const TOOL = 'vendor/bin/patch-status';

    private TempDirectory $store;
    private TemporaryDirectoryTrackingFilesystem $filesystem;

    /** @var list<QueueEntry> */
    private array $entries = [];

    protected function setUp(): void
    {
        $this->store = TempDirectory::create();
        $this->filesystem = new TemporaryDirectoryTrackingFilesystem(new LocalFilesystem());
    }

    protected function tearDown(): void
    {
        $this->store->remove();
    }

    public function test_it_reverses_applied_entries_newest_first_and_restores_the_pristine_files(): void
    {
        $this->storeFile(self::FILE_A, ['three']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('second.patch', $this->diff(self::FILE_A, ['two'], ['three']));

        $result = $this->rewind();

        self::assertTrue($result->success);
        self::assertTrue($result->changed);
        self::assertSame([RewindOutcome::Reversed, RewindOutcome::Reversed], $this->outcomes($result));
        self::assertSame(
            ['m2-hotfixes/second.patch', 'm2-hotfixes/first.patch'],
            array_map(static fn (RewindStep $step): string => $step->entry->path, $result->steps),
        );
        self::assertSame("one\n", $this->contents(self::FILE_A));
    }

    public function test_it_deletes_a_file_that_a_reversed_entry_created(): void
    {
        $this->storeFile(self::FILE_A, ['a']);
        $this->storeFile(self::TOOL, ['tool']);
        $this->enqueue('create.patch', $this->diff(self::TOOL, [], ['tool']));

        $result = $this->rewind();

        self::assertSame([RewindOutcome::Reversed], $this->outcomes($result));
        self::assertFileDoesNotExist($this->store->path(self::TOOL));
    }

    public function test_it_makes_the_queue_apply_cleanly_again_when_every_entry_rewrites_a_shared_file(): void
    {
        $this->storeFile(self::TOOL, ['v3']);
        $this->storeFile(self::FILE_A, ['a3']);
        $this->enqueue('one.patch', $this->diff(self::TOOL, [], ['v1']) . $this->diff(self::FILE_A, ['a0'], ['a1']));
        $this->enqueue('two.patch', $this->pair(['v1', 'a1'], ['v2', 'a2']));
        $this->enqueue('three.patch', $this->pair(['v2', 'a2'], ['v3', 'a3']));

        $result = $this->rewind();

        self::assertTrue($result->success);
        self::assertSame(
            [RewindOutcome::Reversed, RewindOutcome::Reversed, RewindOutcome::Reversed],
            $this->outcomes($result),
        );
        self::assertFileDoesNotExist($this->store->path(self::TOOL));
        self::assertSame("a0\n", $this->contents(self::FILE_A));
    }

    public function test_it_changes_nothing_and_succeeds_on_a_fresh_install_where_entries_are_chained(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('second.patch', $this->diff(self::FILE_A, ['two'], ['three']));
        $this->enqueue('create.patch', $this->diff(self::TOOL, [], ['t1']));
        $this->enqueue('edit.patch', $this->diff(self::TOOL, ['t1'], ['t2']));
        $before = $this->checksums();

        $result = $this->rewind();

        self::assertTrue($result->success);
        self::assertFalse($result->changed);
        self::assertSame(array_fill(0, 4, RewindOutcome::NotApplied), $this->outcomes($result));
        self::assertSame($before, $this->checksums());
    }

    public function test_it_reverses_only_the_applied_prefix_of_a_half_applied_queue(): void
    {
        $this->storeFile(self::FILE_A, ['three']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('second.patch', $this->diff(self::FILE_A, ['two'], ['three']));
        $this->enqueue('third.patch', $this->diff(self::FILE_A, ['three'], ['four']));

        $result = $this->rewind();

        self::assertTrue($result->success);
        self::assertSame(
            [RewindOutcome::NotApplied, RewindOutcome::Reversed, RewindOutcome::Reversed],
            $this->outcomes($result),
        );
        self::assertSame("one\n", $this->contents(self::FILE_A));
    }

    public function test_it_succeeds_when_the_same_patch_is_queued_twice(): void
    {
        $this->storeFile(self::FILE_A, ['two']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('again.patch', $this->diff(self::FILE_A, ['one'], ['two']));

        $result = $this->rewind();

        self::assertTrue($result->success);
        self::assertSame([RewindOutcome::Reversed, RewindOutcome::NotApplied], $this->outcomes($result));
        self::assertSame("one\n", $this->contents(self::FILE_A));
    }

    public function test_it_skips_entries_that_are_not_applied(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));

        $result = $this->rewind();

        self::assertTrue($result->success);
        self::assertFalse($result->changed);
        self::assertSame([RewindOutcome::NotApplied], $this->outcomes($result));
        self::assertSame("one\n", $this->contents(self::FILE_A));
    }

    public function test_it_does_not_report_an_unapplied_file_creation_as_reversed(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('create.patch', $this->diff(self::FILE_B, [], ['fresh']));

        $result = $this->rewind();

        self::assertSame([RewindOutcome::NotApplied], $this->outcomes($result));
        self::assertFalse($result->changed);
        self::assertFileDoesNotExist($this->store->path(self::FILE_B));
    }

    public function test_it_does_not_reverse_a_creation_whose_file_is_missing_even_when_patch_accepts_it(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('create.patch', $this->diff(self::FILE_B, [], ['fresh']));
        $patch = new FakePatchBinary(static fn (PatchCall $call): PatchRun => new PatchRun(0));

        $result = $this->rewind([], false, $patch);

        self::assertSame([RewindOutcome::NotApplied], $this->outcomes($result));
        self::assertFalse($result->changed);
    }

    public function test_it_deletes_the_empty_file_a_patch_program_leaves_after_reversing_a_creation(): void
    {
        $this->storeFile(self::FILE_B, ['fresh']);
        $this->enqueue('create.patch', $this->diff(self::FILE_B, [], ['fresh']));
        $patch = new FakePatchBinary(static function (PatchCall $call): PatchRun {
            if ($call->reverse && !$call->dryRun) {
                file_put_contents($call->workDir . '/' . self::FILE_B, '');
            }

            return new PatchRun($call->reverse || !is_file($call->workDir . '/' . self::FILE_B) ? 0 : 1);
        });

        $result = $this->rewind([], false, $patch);

        self::assertSame([RewindOutcome::Reversed], $this->outcomes($result));
        self::assertFileDoesNotExist($this->store->path(self::FILE_B));
    }

    public function test_it_skips_entries_whose_target_packages_are_not_installed(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('replaced.patch', $this->diff(self::FILE_B, ['x'], ['y']));

        $result = $this->rewind(['magento/module-b']);

        self::assertTrue($result->success);
        self::assertSame([RewindOutcome::SkippedNotInstalled], $this->outcomes($result));
    }

    public function test_it_reports_the_entry_that_would_not_apply_after_rewinding_and_changes_no_file(): void
    {
        $this->storeFile(self::FILE_A, ['x']);
        $this->storeFile(self::FILE_B, ['two']);
        $this->enqueue('middle.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('newest.patch', $this->diff(self::FILE_B, ['one'], ['two']));
        $before = $this->checksums();

        $result = $this->rewind();

        self::assertFalse($result->success);
        self::assertFalse($result->changed);
        self::assertSame([RewindOutcome::WouldReverse, RewindOutcome::Blocked], $this->outcomes($result));
        $blocked = $result->blockedStep();
        self::assertNotNull($blocked);
        self::assertSame('m2-hotfixes/middle.patch', $blocked->entry->path);
        self::assertNotSame('', $blocked->output);
        self::assertSame($before, $this->checksums());
    }

    public function test_it_succeeds_without_changes_when_nothing_is_applied_even_if_the_queue_would_not_apply(): void
    {
        $this->storeFile(self::FILE_A, ['x']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));

        $result = $this->rewind();

        self::assertTrue($result->success);
        self::assertFalse($result->changed);
        self::assertNull($result->blockedStep());
        self::assertSame([RewindOutcome::NotApplied], $this->outcomes($result));
    }

    public function test_dry_run_reports_the_same_outcomes_as_a_real_run_without_changing_files(): void
    {
        $this->storeFile(self::FILE_A, ['three']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('second.patch', $this->diff(self::FILE_A, ['two'], ['three']));
        $this->enqueue('third.patch', $this->diff(self::FILE_A, ['three'], ['four']));
        $before = $this->checksums();

        $dry = $this->rewind([], true);

        self::assertSame($before, $this->checksums());
        self::assertFalse($dry->changed);
        self::assertTrue($dry->success);
        self::assertSame(
            [RewindOutcome::NotApplied, RewindOutcome::WouldReverse, RewindOutcome::WouldReverse],
            $this->outcomes($dry),
        );

        $real = $this->rewind();

        self::assertSame(
            array_map(
                static fn (RewindOutcome $o): RewindOutcome => $o === RewindOutcome::WouldReverse
                    ? RewindOutcome::Reversed
                    : $o,
                $this->outcomes($dry),
            ),
            $this->outcomes($real),
        );
    }

    public function test_it_leaves_no_orig_or_rej_files_and_no_temporary_directories(): void
    {
        $this->storeFile(self::FILE_A, ['h1', 'h2', 'two']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));

        $result = $this->rewind();

        self::assertSame([RewindOutcome::Reversed], $this->outcomes($result));
        self::assertSame("h1\nh2\none\n", $this->contents(self::FILE_A));
        foreach (array_keys($this->checksums()) as $file) {
            self::assertStringEndsNotWith('.orig', $file);
            self::assertStringEndsNotWith('.rej', $file);
        }
        self::assertNotSame([], $this->filesystem->temporaryDirectories());
        foreach ($this->filesystem->temporaryDirectories() as $directory) {
            self::assertDirectoryDoesNotExist($directory);
        }
    }

    /**
     * @param list<string> $replaced
     */
    private function rewind(array $replaced = [], bool $dryRun = false, ?PatchBinary $patch = null): RewindResult
    {
        $installation = new Installation(
            $this->store->path(),
            [],
            $replaced,
            Edition::OpenSource,
            '9.9.9',
            null,
            false,
        );
        $rewinder = new QueueRewinder(
            $patch ?? new SystemPatchBinary(new SymfonyProcessRunner()),
            new DiffParser(),
            $this->filesystem,
        );

        return $rewinder->rewind($installation, new PatchQueue($this->entries), $dryRun);
    }

    /**
     * @return list<RewindOutcome>
     */
    private function outcomes(RewindResult $result): array
    {
        return array_map(static fn (RewindStep $step): RewindOutcome => $step->outcome, $result->steps);
    }

    /**
     * @param array{string, string} $old tool and module A lines
     * @param array{string, string} $new
     */
    private function pair(array $old, array $new): string
    {
        return $this->diff(self::TOOL, [$old[0]], [$new[0]]) . $this->diff(self::FILE_A, [$old[1]], [$new[1]]);
    }

    private function contents(string $relative): string
    {
        return (string) file_get_contents($this->store->path($relative));
    }

    /**
     * @param list<string> $lines
     */
    private function storeFile(string $relative, array $lines): void
    {
        $path = $this->store->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    private function enqueue(string $name, string $diff): void
    {
        $relative = 'm2-hotfixes/' . $name;
        $this->storeFile($relative, []);
        file_put_contents($this->store->path($relative), $diff);
        $this->entries[] = new QueueEntry(
            count($this->entries) + 1,
            QueueOrigin::Hotfix,
            null,
            $relative,
            hash('sha256', $diff),
        );
    }

    /**
     * Rewrites a whole (small) file in one hunk; an empty `$old` creates the file.
     *
     * @param list<string> $old
     * @param list<string> $new
     */
    private function diff(string $file, array $old, array $new): string
    {
        $header = sprintf("diff --git a/%1\$s b/%1\$s\n", $file);
        $from = $old === [] ? '/dev/null' : 'a/' . $file;
        $body = '';
        foreach ($old as $line) {
            $body .= '-' . $line . "\n";
        }
        foreach ($new as $line) {
            $body .= '+' . $line . "\n";
        }

        return $header . sprintf(
            "--- %s\n+++ b/%s\n@@ -%d,%d +1,%d @@\n%s",
            $from,
            $file,
            $old === [] ? 0 : 1,
            count($old),
            count($new),
            $body,
        );
    }

    /**
     * @return array<string, string>
     */
    private function checksums(): array
    {
        $sums = [];
        $root = $this->store->path();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            self::assertInstanceOf(SplFileInfo::class, $file);
            $sums[substr($file->getPathname(), strlen($root) + 1)] = (string) hash_file('sha256', $file->getPathname());
        }
        ksort($sums);

        return $sums;
    }
}
