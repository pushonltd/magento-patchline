<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Detection;

use Patchline\Detection\PatchRun;
use Patchline\Detection\SystemPatchBinary;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Infrastructure\SymfonyProcessRunner;
use Patchline\Tests\Double\FakeProcessRunner;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemPatchBinary::class)]
#[CoversClass(PatchRun::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
final class SystemPatchBinaryTest extends TestCase
{
    private const DIFF = "--- a/file.txt\n+++ b/file.txt\n@@ -1,3 +1,3 @@\n one\n-two\n+TWO\n three\n";

    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
        file_put_contents($this->dir->path('file.txt'), "one\ntwo\nthree\n");
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    private function binary(): SystemPatchBinary
    {
        return new SystemPatchBinary(new SymfonyProcessRunner());
    }

    public function test_it_reports_success_for_a_forward_dry_run_of_an_unapplied_diff(): void
    {
        $run = $this->binary()->dryRun(self::DIFF, $this->dir->path(), false);

        self::assertTrue($run->succeeded);
        self::assertSame(0, $run->exitCode);
    }

    public function test_it_reports_failure_for_a_reverse_dry_run_of_an_unapplied_diff(): void
    {
        $run = $this->binary()->dryRun(self::DIFF, $this->dir->path(), true);

        self::assertFalse($run->succeeded);
        self::assertNotSame(0, $run->exitCode);
    }

    public function test_it_reports_success_for_a_reverse_dry_run_of_an_applied_diff(): void
    {
        file_put_contents($this->dir->path('file.txt'), "one\nTWO\nthree\n");

        $run = $this->binary()->dryRun(self::DIFF, $this->dir->path(), true);

        self::assertTrue($run->succeeded);
    }

    public function test_it_applies_and_reverse_applies_a_diff_in_a_work_directory(): void
    {
        $applied = $this->binary()->apply(self::DIFF, $this->dir->path(), false);
        self::assertTrue($applied->succeeded);
        self::assertSame("one\nTWO\nthree\n", file_get_contents($this->dir->path('file.txt')));

        $reverted = $this->binary()->apply(self::DIFF, $this->dir->path(), true);
        self::assertTrue($reverted->succeeded);
        self::assertSame("one\ntwo\nthree\n", file_get_contents($this->dir->path('file.txt')));
    }

    public function test_it_does_not_modify_files_during_a_dry_run(): void
    {
        $this->binary()->dryRun(self::DIFF, $this->dir->path(), false);

        self::assertSame("one\ntwo\nthree\n", file_get_contents($this->dir->path('file.txt')));
        self::assertSame(['.', '..', 'file.txt'], scandir($this->dir->path()));
    }

    public function test_it_removes_its_temporary_diff_file_after_running(): void
    {
        $seen = null;
        $runner = new FakeProcessRunner();
        $runner->enqueue(static function (array $command) use (&$seen): ProcessResult {
            $index = array_search('-i', $command, true);
            $seen = is_int($index) ? $command[$index + 1] : null;
            self::assertIsString($seen);
            self::assertFileExists($seen);

            return new ProcessResult(0, '', '');
        });

        (new SystemPatchBinary($runner))->dryRun(self::DIFF, $this->dir->path(), false);

        self::assertIsString($seen);
        self::assertFileDoesNotExist($seen);
    }

    public function test_it_reports_whether_a_patch_binary_is_available(): void
    {
        self::assertTrue($this->binary()->isAvailable());

        $runner = (new FakeProcessRunner())->enqueue(new ProcessResult(127, '', 'not found'));
        self::assertFalse((new SystemPatchBinary($runner))->isAvailable());
    }
}
