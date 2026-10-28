<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Verify;

use FilesystemIterator;
use Patchline\Detection\DiffParser;
use Patchline\Detection\DiffSection;
use Patchline\Detection\FilteredDiff;
use Patchline\Detection\PatchRun;
use Patchline\Detection\SystemPatchBinary;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Infrastructure\SymfonyProcessRunner;
use Patchline\Installation\Edition;
use Patchline\Installation\Installation;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use Patchline\Tests\Double\TempDirectory;
use Patchline\Tests\Double\TemporaryDirectoryTrackingFilesystem;
use Patchline\Verify\QueueReplayer;
use Patchline\Verify\ReplayOutcome;
use Patchline\Verify\ReplayStep;
use Patchline\Verify\VerifyResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(QueueReplayer::class)]
#[CoversClass(ReplayStep::class)]
#[CoversClass(VerifyResult::class)]
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
final class QueueReplayerTest extends TestCase
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

    public function test_it_succeeds_when_every_queued_patch_applies_in_order(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('second.patch', $this->diff(self::FILE_A, ['two'], ['three']));

        $result = $this->replay();

        self::assertTrue($result->success);
        self::assertSame([ReplayOutcome::WouldApply, ReplayOutcome::WouldApply], $this->outcomes($result));
        self::assertSame([], $result->notes);
    }

    public function test_it_reports_an_already_applied_patch_as_skipped_like_ece_patches_does(): void
    {
        $this->storeFile(self::FILE_A, ['two']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('same-again.patch', $this->diff(self::FILE_A, ['one'], ['two']));

        $result = $this->replay();

        self::assertTrue($result->success);
        self::assertSame([ReplayOutcome::WouldApply, ReplayOutcome::AlreadyApplied], $this->outcomes($result));
    }

    public function test_it_reports_the_first_patch_that_would_fail_and_stops(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('ok.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('bad.patch', $this->diff(self::FILE_A, ['unrelated'], ['x']));
        $this->enqueue('never.patch', $this->diff(self::FILE_A, ['two'], ['three']));

        $result = $this->replay();

        self::assertFalse($result->success);
        self::assertSame([ReplayOutcome::WouldApply, ReplayOutcome::Failed], $this->outcomes($result));
        $failed = $result->failedStep();
        self::assertNotNull($failed);
        self::assertSame('m2-hotfixes/bad.patch', $failed->entry->path);
        self::assertNotSame('', $failed->output);
    }

    public function test_it_accepts_an_older_monthly_patch_by_ignoring_the_patch_status_tool_and_flags_it(): void
    {
        $this->storeFile(self::TOOL, ['tool v2']);
        $this->storeFile(self::FILE_A, ['a v1']);
        $july = $this->diff(self::TOOL, ['tool v0'], ['tool v1']) . $this->diff(self::FILE_A, ['a v0'], ['a v1']);
        $this->enqueue('july.patch', $july);

        $result = $this->replay();

        self::assertTrue($result->success);
        self::assertSame([ReplayOutcome::AlreadyAppliedIgnoringPatchStatus], $this->outcomes($result));
        self::assertCount(1, $result->notes);
        self::assertStringContainsString('vendor/bin/patch-status', $result->notes[0]);
        self::assertStringContainsString('ece-patches apply will fail here', $result->notes[0]);
    }

    public function test_it_replays_a_diff_that_creates_a_new_file(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('create.patch', $this->diff(self::FILE_B, [], ['fresh']));
        $this->enqueue('edit.patch', $this->diff(self::FILE_B, ['fresh'], ['edited']));

        $result = $this->replay();

        self::assertTrue($result->success);
        self::assertSame([ReplayOutcome::WouldApply, ReplayOutcome::WouldApply], $this->outcomes($result));
    }

    public function test_it_drops_sections_for_replaced_packages_before_replaying_like_ece_patches_does(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue(
            'mixed.patch',
            $this->diff(self::FILE_B, ['not in store'], ['x']) . $this->diff(self::FILE_A, ['one'], ['two']),
        );

        $result = $this->replay(['magento/module-b']);

        self::assertTrue($result->success);
        self::assertSame([ReplayOutcome::WouldApply], $this->outcomes($result));
    }

    public function test_it_skips_a_queued_patch_that_only_targets_replaced_packages(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('replaced.patch', $this->diff(self::FILE_B, ['not in store'], ['x']));

        $result = $this->replay(['magento/module-b']);

        self::assertTrue($result->success);
        self::assertSame([ReplayOutcome::SkippedNotInstalled], $this->outcomes($result));
    }

    public function test_it_replays_on_pristine_files_by_first_reversing_applied_entries_newest_first(): void
    {
        $this->storeFile(self::FILE_A, ['two']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('second.patch', $this->diff(self::FILE_A, ['two'], ['three']));

        $result = $this->replay();

        self::assertTrue($result->success);
        self::assertSame([ReplayOutcome::WouldApply, ReplayOutcome::WouldApply], $this->outcomes($result));
    }

    public function test_it_succeeds_for_a_store_where_a_newer_patch_changed_lines_of_an_older_applied_patch(): void
    {
        $this->storeFile(self::FILE_A, ['three']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('second.patch', $this->diff(self::FILE_A, ['two'], ['three']));

        $result = $this->replay();

        self::assertTrue($result->success);
        self::assertSame([ReplayOutcome::WouldApply, ReplayOutcome::WouldApply], $this->outcomes($result));
    }

    public function test_it_notes_that_in_place_re_run_would_fail_while_a_fresh_install_succeeds(): void
    {
        $this->storeFile(self::FILE_A, ['three']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('second.patch', $this->diff(self::FILE_A, ['two'], ['three']));

        $result = $this->replay();

        self::assertSame(
            ['Re-running ece-patches apply on this already patched vendor/ would stop at m2-hotfixes/first.patch'
                . ' (a newer patch changed the same lines). A fresh composer install applies cleanly.'],
            $result->notes,
        );
    }

    public function test_it_adds_no_in_place_note_when_re_running_in_place_also_works(): void
    {
        $this->storeFile(self::FILE_A, ['two']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));

        self::assertSame([], $this->replay()->notes);
    }

    public function test_it_still_fails_when_the_queue_cannot_be_applied_to_pristine_files(): void
    {
        $this->storeFile(self::FILE_A, ['unrelated']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));

        $result = $this->replay();

        self::assertFalse($result->success);
        self::assertSame([ReplayOutcome::Failed], $this->outcomes($result));
    }

    public function test_it_never_modifies_files_in_the_store_root(): void
    {
        $this->storeFile(self::FILE_A, ['one']);
        $this->enqueue('first.patch', $this->diff(self::FILE_A, ['one'], ['two']));
        $this->enqueue('create.patch', $this->diff(self::FILE_B, [], ['fresh']));
        $this->enqueue('third.patch', $this->diff(self::FILE_A, ['two'], ['three']));
        $before = $this->checksums();

        $this->replay();

        self::assertSame($before, $this->checksums());
        self::assertNotSame([], $this->filesystem->temporaryDirectories());
        foreach ($this->filesystem->temporaryDirectories() as $directory) {
            self::assertDirectoryDoesNotExist($directory);
        }
    }

    /**
     * @param list<string> $replaced
     */
    private function replay(array $replaced = []): VerifyResult
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
        $replayer = new QueueReplayer(
            new SystemPatchBinary(new SymfonyProcessRunner()),
            new DiffParser(),
            $this->filesystem,
        );

        return $replayer->replay($installation, new PatchQueue($this->entries));
    }

    /**
     * @return list<ReplayOutcome>
     */
    private function outcomes(VerifyResult $result): array
    {
        return array_map(static fn (ReplayStep $step): ReplayOutcome => $step->outcome, $result->steps);
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
