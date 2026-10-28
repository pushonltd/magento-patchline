<?php

declare(strict_types=1);

namespace Patchline\Tests\Cli;

use Patchline\Tests\Support\CliStore;
use Patchline\Tests\Support\PhpProcess;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ApplicationTest extends TestCase
{
    private ?CliStore $store = null;

    protected function tearDown(): void
    {
        if ($this->store === null) {
            return;
        }

        $this->store->remove();
    }

    private function createStore(bool $patched): CliStore
    {
        $this->store = CliStore::create($patched);

        return $this->store;
    }

    public function test_it_exits_0_for_status_on_a_fully_patched_store(): void
    {
        $this->createStore(true);

        $result = $this->patchline('status');

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('applied', $result['stdout']);
    }

    public function test_it_exits_1_for_status_when_a_patch_is_missing(): void
    {
        $this->createStore(false);

        $result = $this->patchline('status');

        self::assertSame(1, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('missing', $result['stdout']);
    }

    public function test_it_exits_2_with_a_hint_to_help_for_an_unknown_flag(): void
    {
        $result = PhpProcess::run([$this->bin(), 'status', '--bogus']);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('Run patchline --help for usage.', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function test_it_prints_the_help_hint_exactly_once_for_an_unknown_flag(): void
    {
        $result = PhpProcess::run([$this->bin(), 'status', '--bogus']);

        self::assertStringContainsString('The "--bogus" option does not exist.', $result['stderr']);
        self::assertSame(1, substr_count($result['stderr'], '--help'));
    }

    public function test_it_exits_3_when_the_registry_cannot_be_loaded(): void
    {
        $store = $this->createStore(true);

        $result = PhpProcess::run([
            $this->bin(), 'status', '--root=' . $store->root(),
            '--registry=' . $store->path('nope.json'),
        ]);

        self::assertSame(3, $result['exitCode']);
        self::assertStringContainsString('does not exist', $result['stderr']);
    }

    public function test_it_writes_only_the_json_document_to_stdout_for_json_format(): void
    {
        $this->createStore(true);

        $result = $this->patchline('status', '--format=json');

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertIsArray(json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('', $result['stderr']);
    }

    public function test_it_downloads_missing_patches_with_sync_and_exits_0_afterwards(): void
    {
        $store = $this->createStore(false);

        $result = $this->patchline('sync');

        self::assertSame(0, $result['exitCode'], $result['stderr'] . $result['stdout']);
        self::assertFileExists($store->path('m2-hotfixes/2026-01-13_249-2026-01-001-CE.patch'));
        self::assertSame(0, $this->patchline('status')['exitCode']);
    }

    public function test_it_appends_the_job_summary_to_github_step_summary_when_it_is_set(): void
    {
        $store = $this->createStore(true);
        $summary = $store->path('summary.md');

        $result = $this->patchline('status', '--format=github', env: ['GITHUB_STEP_SUMMARY' => $summary]);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertFileExists($summary);
        self::assertStringContainsString('249-2026-01-001-CE', (string) file_get_contents($summary));
    }

    public function test_it_fails_the_run_on_a_cross_check_disagreement_only_when_cross_check_is_in_fail_on(): void
    {
        $store = $this->createStore(true);
        $this->installPatchStatus($store, ['applied_patches' => [], 'missing_patches' => [CliStore::PATCH_ID]]);

        $ignored = $this->patchlineWith('status', ['--cross-check']);
        $failing = $this->patchlineWith('status', ['--cross-check', '--fail-on=missing,cross-check']);
        $notRun = $this->patchlineWith('status', ['--fail-on=missing,cross-check']);

        self::assertSame(0, $ignored['exitCode'], $ignored['stderr']);
        self::assertStringContainsString('1 disagreement', $ignored['stdout']);
        self::assertSame(1, $failing['exitCode'], $failing['stderr']);
        self::assertStringContainsString('1 disagreement', $failing['stdout']);
        self::assertSame(0, $notRun['exitCode'], $notRun['stderr']);
        self::assertStringNotContainsString('Cross-check', $notRun['stdout']);
    }

    public function test_it_reports_cross_check_agreement_with_adobe_patch_status(): void
    {
        $store = $this->createStore(true);
        $this->installPatchStatus($store, ['applied_patches' => [CliStore::PATCH_ID], 'missing_patches' => []]);

        $result = $this->patchlineWith('status', ['--cross-check', '--fail-on=missing,cross-check']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('Both tools agree on 1 patch.', $result['stdout']);
    }

    public function test_it_warns_and_keeps_the_exit_code_when_cross_check_cannot_run(): void
    {
        $this->createStore(true);

        $result = $this->patchlineWith('status', ['--cross-check', '--fail-on=missing,cross-check']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('cross-check skipped', $result['stdout']);
    }

    /**
     * @param array<string, list<string>> $lists
     */
    private function installPatchStatus(CliStore $store, array $lists): void
    {
        $lists += ['unknown_patches' => []];
        mkdir($store->path('vendor/bin'), 0777, true);
        $script = '<?php echo ' . var_export(json_encode($lists), true) . ';';
        file_put_contents($store->path('vendor/bin/patch-status'), $script);
    }

    /**
     * @param list<string> $flags
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function patchlineWith(string $command, array $flags): array
    {
        return PhpProcess::run([
            $this->bin(), $command, '--no-ansi', ...$flags, '--root=' . $this->store()->root(),
            '--registry=' . $this->store()->registry(),
        ]);
    }

    /**
     * @param array<string, string> $env
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function patchline(string $command, string $flag = '--no-ansi', array $env = []): array
    {
        return PhpProcess::run([
            $this->bin(), $command, $flag, '--root=' . $this->store()->root(),
            '--registry=' . $this->store()->registry(),
        ], null, $env);
    }

    private function bin(): string
    {
        $override = getenv('PATCHLINE_BIN');

        return $override !== false && $override !== '' ? $override : dirname(__DIR__, 2) . '/bin/patchline';
    }

    private function store(): CliStore
    {
        return $this->store ?? throw new \LogicException('Create a store first.');
    }
}
