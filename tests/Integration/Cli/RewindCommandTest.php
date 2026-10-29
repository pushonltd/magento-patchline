<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Cli;

use Patchline\Cli\Application;
use Patchline\Cli\Config;
use Patchline\Cli\ConfigResolver;
use Patchline\Cli\Console\ConsoleApplication;
use Patchline\Cli\Console\PatchlineCommand;
use Patchline\Cli\Console\RewindCommand;
use Patchline\Cli\Console\StreamConsoleOutput;
use Patchline\Cli\Container;
use Patchline\Cli\Output\RewindTextRenderer;
use Patchline\Cli\Output\Style;
use Patchline\Detection\DiffParser;
use Patchline\Detection\DiffSection;
use Patchline\Detection\FilteredDiff;
use Patchline\Detection\PatchRun;
use Patchline\Detection\SystemPatchBinary;
use Patchline\Infrastructure\GuzzleHttpClient;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Infrastructure\SymfonyProcessRunner;
use Patchline\Installation\Installation;
use Patchline\Installation\InstallationReader;
use Patchline\PackageVersion;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueEntryDiff;
use Patchline\Queue\QueueReader;
use Patchline\Queue\Source\CloudPatchesSource;
use Patchline\Queue\Source\HotfixDirSource;
use Patchline\Queue\Source\SourceResult;
use Patchline\Rewind\QueueRewinder;
use Patchline\Rewind\RewindOutcome;
use Patchline\Rewind\RewindResult;
use Patchline\Rewind\RewindStep;
use Patchline\Tests\Double\FakeProcessRunner;
use Patchline\Tests\Support\FixtureStore;
use Patchline\Tests\Support\Golden;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RewindCommand::class)]
#[CoversClass(RewindTextRenderer::class)]
#[CoversClass(Container::class)]
#[CoversClass(ConfigResolver::class)]
#[CoversClass(Application::class)]
#[CoversClass(SystemPatchBinary::class)]
#[UsesClass(ConsoleApplication::class)]
#[UsesClass(PatchlineCommand::class)]
#[UsesClass(StreamConsoleOutput::class)]
#[UsesClass(Style::class)]
#[UsesClass(Config::class)]
#[UsesClass(PackageVersion::class)]
#[UsesClass(QueueRewinder::class)]
#[UsesClass(RewindResult::class)]
#[UsesClass(RewindStep::class)]
#[UsesClass(RewindOutcome::class)]
#[UsesClass(QueueEntryDiff::class)]
#[UsesClass(QueueReader::class)]
#[UsesClass(GuzzleHttpClient::class)]
#[UsesClass(DiffParser::class)]
#[UsesClass(DiffSection::class)]
#[UsesClass(FilteredDiff::class)]
#[UsesClass(PatchRun::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(CloudPatchesSource::class)]
#[UsesClass(HotfixDirSource::class)]
#[UsesClass(SourceResult::class)]
final class RewindCommandTest extends TestCase
{
    private const FILE_A = 'vendor/magento/module-a/a.php';
    private const FILE_B = 'vendor/magento/module-b/b.php';

    private ?FixtureStore $store = null;

    protected function tearDown(): void
    {
        $this->store?->remove();
        $this->store = null;
    }

    public function test_rewind_reverses_the_applied_queue_and_exits_zero(): void
    {
        $store = $this->store();

        $result = $store->runRaw(['rewind']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame("one\n", $this->contents(self::FILE_A));
        self::assertSame("x\n", $this->contents(self::FILE_B));
        self::assertSame('', $result['stderr']);
    }

    public function test_rewind_exits_zero_and_changes_nothing_on_a_freshly_installed_store(): void
    {
        $store = $this->store();
        file_put_contents($store->root() . '/' . self::FILE_A, "one\n");
        file_put_contents($store->root() . '/' . self::FILE_B, "x\n");
        $before = $store->snapshot();

        $result = $store->runRaw(['rewind']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame($before, $store->snapshot());
        self::assertStringContainsString('Nothing was changed.', $result['stdout']);
    }

    public function test_rewind_dry_run_changes_no_file(): void
    {
        $store = $this->store();
        $before = $store->snapshot();

        $result = $store->runRaw(['rewind', '--dry-run']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame($before, $store->snapshot());
        self::assertStringContainsString('would reverse', $result['stdout']);
    }

    public function test_rewind_does_not_load_the_registry(): void
    {
        $store = $this->store();
        self::assertFileExists($store->registry());
        unlink($store->registry());

        $result = $store->runRaw(['rewind']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
    }

    public function test_rewind_exits_one_and_explains_what_to_do_when_an_entry_is_blocked(): void
    {
        $store = $this->store();
        file_put_contents(
            $store->root() . '/m2-hotfixes/2026-02-01_stale.patch',
            "diff --git a/" . self::FILE_A . " b/" . self::FILE_A . "\n--- a/" . self::FILE_A
            . "\n+++ b/" . self::FILE_A . "\n@@ -1,1 +1,1 @@\n-zzz\n+yyy\n",
        );
        $before = $store->snapshot();

        $result = $store->runRaw(['rewind']);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('m2-hotfixes/2026-02-01_stale.patch', $result['stdout']);
        self::assertStringContainsString('Restore the missing hotfix files', $result['stdout']);
        self::assertStringContainsString('Nothing was changed.', $result['stdout']);
        self::assertSame($before, $store->snapshot());
    }

    public function test_rewind_exits_three_when_the_patch_program_is_missing(): void
    {
        $store = $this->store();
        $runner = (new FakeProcessRunner())->enqueue(new ProcessResult(127, '', 'patch: not found'));

        $result = $store->runRaw(['rewind'], [], new Container(processes: $runner));

        self::assertSame(3, $result['exitCode']);
        self::assertStringContainsString('"patch" program is not installed', $result['stderr']);
    }

    public function test_rewind_exits_three_for_an_unreadable_root(): void
    {
        $store = $this->store();

        $stderr = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        self::assertIsResource($stdout);

        $exitCode = (new Application())->run(
            ['rewind', '--root=' . $store->root() . '/missing'],
            ['PATCHLINE_NO_UPDATE_CHECK' => '1'],
            $store->root(),
            $stdout,
            $stderr,
        );

        rewind($stderr);
        self::assertSame(3, $exitCode);
        self::assertStringContainsString('Cannot read the store at', (string) stream_get_contents($stderr));
    }

    public function test_rewind_exits_three_when_magento_cloud_patches_is_not_installed(): void
    {
        $store = $this->store();
        $this->removeTree($store->root() . '/vendor/magento/magento-cloud-patches');
        $before = $store->snapshot();

        $result = $store->runRaw(['rewind']);

        self::assertSame(3, $result['exitCode']);
        self::assertStringContainsString('magento/magento-cloud-patches is not installed', $result['stderr']);
        self::assertSame($before, $store->snapshot());
    }

    public function test_rewind_rejects_status_only_options(): void
    {
        $store = $this->store();

        $result = $store->run(['rewind']);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('--registry', $result['stderr']);
    }

    public function test_rewind_text_output_matches_the_golden_file(): void
    {
        $success = $this->store()->runRaw(['rewind'])['stdout'];
        $this->store?->remove();
        $dryRun = $this->store()->runRaw(['rewind', '--dry-run'])['stdout'];

        self::assertSame(Golden::expected('text-rewind.txt', $success), $success);
        self::assertSame(Golden::expected('text-rewind-dry-run.txt', $dryRun), $dryRun);
    }

    public function test_rewind_is_listed_in_help(): void
    {
        $result = $this->store()->runRaw(['rewind', '--help']);

        self::assertStringContainsString(
            'Reverse the patch queue in vendor/ so ece-patches apply can run again (needs the patch program).',
            $result['stdout'],
        );
    }

    private function store(): FixtureStore
    {
        $this->store = FixtureStore::create('rewind-applied', 'http://127.0.0.1:9');

        return $this->store;
    }

    private function contents(string $relative): string
    {
        return (string) file_get_contents(($this->store ?? $this->store())->root() . '/' . $relative);
    }

    private function removeTree(string $path): void
    {
        foreach (array_diff((array) scandir($path), ['.', '..']) as $name) {
            is_dir($path . '/' . $name) ? $this->removeTree($path . '/' . $name) : unlink($path . '/' . $name);
        }
        rmdir($path);
    }
}
