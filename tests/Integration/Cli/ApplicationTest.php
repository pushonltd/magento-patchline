<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Cli;

use Patchline\Analysis\AnalysisInput;
use Patchline\Analysis\Analyzer;
use Patchline\Analysis\PatchReport;
use Patchline\Analysis\Report;
use Patchline\Analysis\ReportSummary;
use Patchline\Cli\Application;
use Patchline\Cli\Command\CommandResult;
use Patchline\Cli\Command\FailOn;
use Patchline\Cli\Command\Inspection;
use Patchline\Cli\Command\Inspector;
use Patchline\Cli\Command\StatusCommand;
use Patchline\Cli\Command\SyncCommand;
use Patchline\Cli\Command\VerifyCommand;
use Patchline\Cli\Config;
use Patchline\Cli\ConfigResolver;
use Patchline\Cli\Console\ConsoleApplication;
use Patchline\Cli\Console\PatchlineCommand;
use Patchline\Cli\Console\StreamConsoleOutput;
use Patchline\Cli\Container;
use Patchline\Cli\Output\FormattedOutput;
use Patchline\Cli\Output\GithubFormatter;
use Patchline\Cli\Output\JsonFormatter;
use Patchline\Cli\Output\Style;
use Patchline\Cli\Output\TextFormatter;
use Patchline\CrossCheck\CrossCheckResult;
use Patchline\CrossCheck\Disagreement;
use Patchline\CrossCheck\PatchStatusCrossCheck;
use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionResults;
use Patchline\Detection\DiffParser;
use Patchline\Detection\DiffSection;
use Patchline\Detection\DryRunDetector;
use Patchline\Detection\FilteredDiff;
use Patchline\Detection\PatchRun;
use Patchline\Detection\SystemPatchBinary;
use Patchline\Download\Credentials;
use Patchline\Download\CredentialsResolver;
use Patchline\Download\DownloadResult;
use Patchline\Download\PatchDownloader;
use Patchline\Infrastructure\GuzzleHttpClient;
use Patchline\Infrastructure\HttpResponse;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Infrastructure\SymfonyProcessRunner;
use Patchline\Installation\Installation;
use Patchline\Installation\InstallationReader;
use Patchline\PackageVersion;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueEntryDiff;
use Patchline\Queue\Source\CloudPatchesSource;
use Patchline\Queue\Source\HotfixDirSource;
use Patchline\Queue\Source\SourceResult;
use Patchline\Registry\Area;
use Patchline\Registry\ComponentResolver;
use Patchline\Registry\DependencyGraph;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\LoadedRegistry;
use Patchline\Registry\ParseResult;
use Patchline\Registry\Registry;
use Patchline\Registry\RegistryLoader;
use Patchline\Registry\RegistryParser;
use Patchline\Registry\RegistryPatch;
use Patchline\Sync\Action\Download;
use Patchline\Sync\Action\Remove;
use Patchline\Sync\Action\Rename;
use Patchline\Sync\Action\Warn;
use Patchline\Sync\ActionOutcome;
use Patchline\Sync\ExecutionResult;
use Patchline\Sync\Executor;
use Patchline\Sync\Plan;
use Patchline\Sync\Planner;
use Patchline\Sync\SyncOptions;
use Patchline\Tests\Double\ForbiddenFilesystem;
use Patchline\Tests\Support\FixtureStore;
use Patchline\Tests\Support\LocalServer;
use Patchline\Verify\QueueReplayer;
use Patchline\Verify\ReplayOutcome;
use Patchline\Verify\ReplayStep;
use Patchline\Verify\VerifyResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The CLI scenarios of tests/Cli, run in this process so that coverage and mutation testing see them.
 */
#[CoversClass(Application::class)]
#[CoversClass(Container::class)]
#[CoversClass(ConsoleApplication::class)]
#[CoversClass(PatchlineCommand::class)]
#[CoversClass(StreamConsoleOutput::class)]
#[CoversClass(ConfigResolver::class)]
#[CoversClass(Inspector::class)]
#[CoversClass(StatusCommand::class)]
#[CoversClass(SyncCommand::class)]
#[CoversClass(VerifyCommand::class)]
#[UsesClass(AnalysisInput::class)]
#[UsesClass(Analyzer::class)]
#[UsesClass(PatchReport::class)]
#[UsesClass(Report::class)]
#[UsesClass(ReportSummary::class)]
#[UsesClass(CommandResult::class)]
#[UsesClass(FailOn::class)]
#[UsesClass(Inspection::class)]
#[UsesClass(Config::class)]
#[UsesClass(FormattedOutput::class)]
#[UsesClass(GithubFormatter::class)]
#[UsesClass(JsonFormatter::class)]
#[UsesClass(Style::class)]
#[UsesClass(TextFormatter::class)]
#[UsesClass(CrossCheckResult::class)]
#[UsesClass(Disagreement::class)]
#[UsesClass(PatchStatusCrossCheck::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(DetectionResults::class)]
#[UsesClass(DiffParser::class)]
#[UsesClass(DiffSection::class)]
#[UsesClass(DryRunDetector::class)]
#[UsesClass(FilteredDiff::class)]
#[UsesClass(PatchRun::class)]
#[UsesClass(SystemPatchBinary::class)]
#[UsesClass(Credentials::class)]
#[UsesClass(CredentialsResolver::class)]
#[UsesClass(DownloadResult::class)]
#[UsesClass(PatchDownloader::class)]
#[UsesClass(GuzzleHttpClient::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(PackageVersion::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(QueueEntryDiff::class)]
#[UsesClass(CloudPatchesSource::class)]
#[UsesClass(HotfixDirSource::class)]
#[UsesClass(SourceResult::class)]
#[UsesClass(Area::class)]
#[UsesClass(ComponentResolver::class)]
#[UsesClass(DependencyGraph::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(LoadedRegistry::class)]
#[UsesClass(ParseResult::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryLoader::class)]
#[UsesClass(RegistryParser::class)]
#[UsesClass(RegistryPatch::class)]
#[UsesClass(Download::class)]
#[UsesClass(Remove::class)]
#[UsesClass(Rename::class)]
#[UsesClass(Warn::class)]
#[UsesClass(ActionOutcome::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(Executor::class)]
#[UsesClass(Plan::class)]
#[UsesClass(Planner::class)]
#[UsesClass(SyncOptions::class)]
#[UsesClass(QueueReplayer::class)]
#[UsesClass(ReplayOutcome::class)]
#[UsesClass(ReplayStep::class)]
#[UsesClass(VerifyResult::class)]
final class ApplicationTest extends TestCase
{
    private static LocalServer $server;

    private ?FixtureStore $store = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalServer::start(__DIR__ . '/../../Fixtures/http/e2e-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function tearDown(): void
    {
        $this->store?->remove();
        $this->store = null;
    }

    public function test_it_prints_the_usage_and_exits_0_for_help(): void
    {
        $result = $this->runWithoutStore(['--help']);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('Usage:', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function test_it_prints_the_package_version_and_exits_0_for_version(): void
    {
        $result = $this->runWithoutStore(['--version']);

        self::assertSame(0, $result['exitCode']);
        self::assertSame('patchline ' . PackageVersion::current() . "\n", $result['stdout']);
    }

    public function test_it_prints_the_usage_to_stderr_and_exits_2_without_a_command(): void
    {
        $result = $this->runWithoutStore([]);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('Usage:', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function test_it_exits_2_with_a_hint_to_help_for_an_unknown_option(): void
    {
        $result = $this->runWithoutStore(['status', '--bogus']);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString(
            "The \"--bogus\" option does not exist.\nRun patchline --help for usage.\n",
            $result['stderr'],
        );
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidInvocations(): iterable
    {
        yield 'sync only flag on status' => [['status', '--dry-run']];
        yield 'value for a switch' => [['status', '--no-cache=1']];
        yield 'missing value' => [['status', '--registry']];
        yield 'second command' => [['status', 'sync']];
    }

    /**
     * @param list<string> $argv
     */
    #[DataProvider('invalidInvocations')]
    public function test_it_exits_2_with_a_hint_to_help_for_an_invalid_invocation(array $argv): void
    {
        $result = $this->runWithoutStore($argv);

        self::assertSame(2, $result['exitCode']);
        self::assertStringEndsWith("Run patchline --help for usage.\n", $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function test_it_exits_2_with_a_hint_to_help_for_an_unknown_command(): void
    {
        $result = $this->runWithoutStore(['bogus']);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('bogus', $result['stderr']);
        self::assertStringEndsWith("Run patchline --help for usage.\n", $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function test_it_colours_the_text_report_when_ansi_is_forced(): void
    {
        $store = $this->createStore('fully-patched');

        $result = $this->statusWithoutAutomaticNoAnsi($store, ['--ansi'], []);

        self::assertStringContainsString("\033[", $result['stdout']);
    }

    public function test_it_disables_colour_when_no_color_is_set_or_no_ansi_is_given(): void
    {
        $store = $this->createStore('fully-patched');

        $noColor = $this->statusWithoutAutomaticNoAnsi($store, ['--ansi'], ['NO_COLOR' => '1']);
        $noAnsi = $this->statusWithoutAutomaticNoAnsi($store, ['--ansi', '--no-ansi'], []);

        self::assertSame(0, $noColor['exitCode'], $noColor['stderr']);
        self::assertStringNotContainsString("\033[", $noColor['stdout']);
        self::assertSame(0, $noAnsi['exitCode'], $noAnsi['stderr']);
        self::assertStringNotContainsString("\033[", $noAnsi['stdout']);
    }

    public function test_it_exits_3_with_the_message_when_the_registry_cannot_be_loaded(): void
    {
        $store = $this->createStore('fully-patched');

        unlink($store->registry());

        $result = $store->run(['status']);

        self::assertSame(3, $result['exitCode']);
        self::assertStringContainsString('does not exist', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    public function test_it_exits_3_and_hides_the_trace_for_an_unexpected_error(): void
    {
        $application = new Application(new Container(new ForbiddenFilesystem()));

        $result = $this->runWith($application, ['status', '--root=/nowhere'], []);

        self::assertSame(3, $result['exitCode']);
        self::assertStringStartsWith('Unexpected error: ', $result['stderr']);
        self::assertStringNotContainsString('#0', $result['stderr']);
    }

    public function test_it_prints_the_trace_for_an_unexpected_error_when_patchline_debug_is_1(): void
    {
        $application = new Application(new Container(new ForbiddenFilesystem()));

        $result = $this->runWith($application, ['status', '--root=/nowhere'], ['PATCHLINE_DEBUG' => '1']);

        self::assertSame(3, $result['exitCode']);
        self::assertStringContainsString('#0', $result['stderr']);
    }

    /**
     * @param array<string, string> $expected patch id => state
     */
    #[DataProvider('statesPerStore')]
    public function test_it_reports_the_states_of_a_fixture_store_and_exits_with_the_findings(
        string $fixture,
        array $expected,
        int $exitCode,
    ): void {
        $store = $this->createStore($fixture);

        $result = $store->run(['status', '--format=json']);

        self::assertSame($exitCode, $result['exitCode'], $result['stderr']);
        self::assertSame($expected, $this->states($result['stdout']));
    }

    /**
     * @return iterable<string, array{string, array<string, string>, int}>
     */
    public static function statesPerStore(): iterable
    {
        yield 'applied' => ['fully-patched', [
            FixtureStore::A => 'applied', FixtureStore::B => 'applied',
            FixtureStore::C => 'entitled', FixtureStore::D => 'applied',
        ], 0];
        yield 'missing and entitled' => ['nothing-patched', [
            FixtureStore::A => 'missing', FixtureStore::B => 'missing',
            FixtureStore::C => 'entitled', FixtureStore::D => 'missing',
        ], 1];
        yield 'queued by cloud patches' => ['queued-by-cloud-patches', [
            FixtureStore::A => 'queued', FixtureStore::B => 'queued',
            FixtureStore::C => 'entitled', FixtureStore::D => 'missing',
        ], 1];
        yield 'queued in hotfixes' => ['queued-in-hotfixes', [
            FixtureStore::A => 'queued', FixtureStore::B => 'queued',
            FixtureStore::C => 'entitled', FixtureStore::D => 'missing',
        ], 1];
        yield 'duplicate' => ['duplicate', [
            FixtureStore::A => 'duplicate', FixtureStore::B => 'applied',
            FixtureStore::C => 'entitled', FixtureStore::D => 'applied',
        ], 1];
        yield 'misordered' => ['misordered', [
            FixtureStore::A => 'queued', FixtureStore::B => 'misordered',
            FixtureStore::C => 'entitled', FixtureStore::D => 'applied',
        ], 1];
        yield 'unknown' => ['locally-modified', [
            FixtureStore::A => 'unknown', FixtureStore::B => 'applied',
            FixtureStore::C => 'entitled', FixtureStore::D => 'applied',
        ], 0];
        yield 'not applicable' => ['replaced-package', [
            FixtureStore::A => 'applied', FixtureStore::B => 'applied',
            FixtureStore::C => 'entitled', FixtureStore::D => 'not_applicable',
        ], 0];
    }

    public function test_it_syncs_a_store_with_nothing_patched_into_dated_files_that_verify_accepts(): void
    {
        $store = $this->createStore('nothing-patched');

        $sync = $store->run(['sync']);
        $verify = $store->run(['verify']);

        self::assertSame(0, $sync['exitCode'], $sync['stderr']);
        self::assertSame([
            'm2-hotfixes/2026-01-13_249-2026-01-001-CE.patch',
            'm2-hotfixes/2026-02-10_249-2026-02-001-CE.patch',
            'm2-hotfixes/2026-04-14_249-2026-04-001-CE.patch',
        ], $this->hotfixes($store));
        self::assertSame(0, $verify['exitCode'], $verify['stdout'] . $verify['stderr']);
        self::assertStringContainsString('Verify passed', $verify['stdout']);
    }

    public function test_it_fails_verify_when_the_queue_does_not_replay(): void
    {
        $store = $this->createStore('misordered');

        $result = $store->run(['verify']);

        self::assertSame(1, $result['exitCode'], $result['stderr']);
    }

    public function test_it_leaves_the_store_untouched_with_sync_dry_run(): void
    {
        $store = $this->createStore('nothing-patched');
        $before = $store->snapshot();

        $result = $store->run(['sync', '--dry-run']);

        self::assertSame($before, $store->snapshot());
        self::assertStringContainsString('m2-hotfixes/2026-01-13_249-2026-01-001-CE.patch', $result['stdout']);
        self::assertSame(1, $result['exitCode'], $result['stderr']);
    }

    public function test_it_fixes_a_misordered_store_with_sync_adopt(): void
    {
        $store = $this->createStore('misordered');

        $adopt = $store->run(['sync', '--adopt']);
        $states = $this->states($store->run(['status', '--format=json'])['stdout']);

        self::assertSame(0, $adopt['exitCode'], $adopt['stdout'] . $adopt['stderr']);
        self::assertSame('queued', $states[FixtureStore::A]);
        self::assertSame('queued', $states[FixtureStore::B]);
    }

    public function test_it_fixes_a_duplicate_store_with_sync_prune(): void
    {
        $store = $this->createStore('duplicate');

        $prune = $store->run(['sync', '--prune']);

        self::assertSame(0, $prune['exitCode'], $prune['stdout'] . $prune['stderr']);
        self::assertFileDoesNotExist($store->root() . '/m2-hotfixes/copy-of-a.patch');
    }

    public function test_it_exits_1_after_sync_when_an_action_fails(): void
    {
        $store = $this->createStore('nothing-patched');
        file_put_contents($store->root() . '/m2-hotfixes', 'not a directory');

        $result = $store->run(['sync']);

        self::assertSame(1, $result['exitCode']);
        $hotfixes = (string) file_get_contents($store->root() . '/m2-hotfixes');
        self::assertStringContainsString('not a directory', $hotfixes);
    }

    public function test_it_downloads_an_entitled_patch_only_with_credentials_from_composer_auth(): void
    {
        $store = $this->createStore('entitled-on-open-source');
        $target = $store->root() . '/m2-hotfixes/2026-03-10_249-2026-03-001-CE.patch';

        $without = $store->run(['sync', '--include-entitled']);
        $beforeCredentials = file_exists($target);
        $with = $store->run(['sync', '--include-entitled'], ['COMPOSER_AUTH' => FixtureStore::COMPOSER_AUTH]);

        self::assertFalse($beforeCredentials);
        self::assertStringContainsString('needs repo.magento.com credentials', $without['stdout']);
        self::assertFileExists($target);
        self::assertSame(0, $with['exitCode'], $with['stdout'] . $with['stderr']);
        self::assertStringNotContainsString('e2e-secret', $with['stdout'] . $with['stderr']);
    }

    public function test_it_appends_the_job_summary_to_github_step_summary_when_it_is_set(): void
    {
        $store = $this->createStore('fully-patched');
        $summary = $store->root() . '/../work/summary.md';

        $result = $store->run(['status', '--format=github'], ['GITHUB_STEP_SUMMARY' => $summary]);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString(FixtureStore::A, (string) file_get_contents($summary));
        self::assertSame('', $result['stderr']);
    }

    public function test_it_warns_on_stderr_when_the_job_summary_cannot_be_written(): void
    {
        $store = $this->createStore('fully-patched');
        $summary = $store->root() . '/no-such-directory/summary.md';

        $result = $store->run(['status', '--format=github'], ['GITHUB_STEP_SUMMARY' => $summary]);

        self::assertSame(0, $result['exitCode']);
        self::assertSame(sprintf("Could not write the job summary to %s.\n", $summary), $result['stderr']);
    }

    public function test_it_fails_the_run_on_a_cross_check_disagreement_only_when_cross_check_is_in_fail_on(): void
    {
        $store = $this->createStore('fully-patched');
        $this->installPatchStatus($store, ['applied_patches' => [], 'missing_patches' => [FixtureStore::A]]);

        $ignored = $store->run(['status', '--cross-check']);
        $failing = $store->run(['status', '--cross-check', '--fail-on=missing,cross-check']);
        $notRun = $store->run(['status', '--fail-on=missing,cross-check']);

        self::assertSame(0, $ignored['exitCode'], $ignored['stderr']);
        self::assertStringContainsString('disagreement', $ignored['stdout']);
        self::assertSame(1, $failing['exitCode'], $failing['stderr']);
        self::assertSame(0, $notRun['exitCode'], $notRun['stderr']);
        self::assertStringNotContainsString('Cross-check', $notRun['stdout']);
    }

    public function test_it_keeps_a_failing_exit_code_when_cross_check_disagrees_too(): void
    {
        $store = $this->createStore('nothing-patched');
        $this->installPatchStatus($store, ['applied_patches' => [], 'missing_patches' => []]);

        $result = $store->run(['status', '--cross-check', '--fail-on=missing,cross-check']);

        self::assertSame(1, $result['exitCode']);
    }

    public function test_it_colours_nothing_when_stdout_is_not_a_terminal(): void
    {
        $store = $this->createStore('fully-patched');

        $result = $store->run(['status']);

        self::assertStringNotContainsString("\033[", $result['stdout']);
    }

    /**
     * @param list<string> $argv
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runWithoutStore(array $argv): array
    {
        return $this->runWith(new Application(new Container(new ForbiddenFilesystem())), $argv, []);
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runWith(Application $application, array $argv, array $env): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $exitCode = $application->run($argv, $env, '/nowhere', $stdout, $stderr);
        rewind($stdout);
        rewind($stderr);

        return [
            'stdout' => (string) stream_get_contents($stdout),
            'stderr' => (string) stream_get_contents($stderr),
            'exitCode' => $exitCode,
        ];
    }

    /**
     * @param list<string> $flags
     * @param array<string, string> $env
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function statusWithoutAutomaticNoAnsi(FixtureStore $store, array $flags, array $env): array
    {
        $argv = ['status', ...$flags, '--root=' . $store->root(), '--registry=' . $store->registry()];

        return $this->runWith(new Application(), $argv, ['PATCHLINE_ALLOW_INSECURE_REGISTRY' => '1', ...$env]);
    }

    private function createStore(string $fixture): FixtureStore
    {
        $this->store = FixtureStore::create($fixture, self::$server->baseUrl());

        return $this->store;
    }

    /**
     * @return list<string>
     */
    private function hotfixes(FixtureStore $store): array
    {
        return array_values(array_filter(
            array_keys($store->snapshot()),
            static fn (string $path): bool => str_starts_with($path, 'm2-hotfixes/'),
        ));
    }

    /**
     * @param array<string, list<string>> $lists
     */
    private function installPatchStatus(FixtureStore $store, array $lists): void
    {
        $lists += ['unknown_patches' => []];
        mkdir($store->root() . '/vendor/bin', 0777, true);
        $script = '<?php echo ' . var_export(json_encode($lists), true) . ';';
        file_put_contents($store->root() . '/vendor/bin/patch-status', $script);
    }

    /**
     * @return array<string, string> patch id => state
     */
    private function states(string $json): array
    {
        $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        $states = [];
        foreach (is_array($report['patches'] ?? null) ? $report['patches'] : [] as $patch) {
            self::assertIsArray($patch);
            self::assertIsString($patch['id']);
            self::assertIsString($patch['state']);
            $states[$patch['id']] = $patch['state'];
        }
        ksort($states);

        return $states;
    }
}
