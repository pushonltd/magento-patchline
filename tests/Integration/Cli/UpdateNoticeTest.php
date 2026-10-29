<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Cli;

use DateTimeImmutable;
use LogicException;
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
use Patchline\Cli\Config;
use Patchline\Cli\ConfigResolver;
use Patchline\Cli\Console\ConsoleApplication;
use Patchline\Cli\Console\PatchlineCommand;
use Patchline\Cli\Console\RewindCommand;
use Patchline\Cli\Console\StreamConsoleOutput;
use Patchline\Cli\Container;
use Patchline\Cli\Output\FormattedOutput;
use Patchline\Cli\Output\GithubFormatter;
use Patchline\Cli\Output\JsonFormatter;
use Patchline\Cli\Output\RewindTextRenderer;
use Patchline\Cli\Output\Style;
use Patchline\Cli\Output\TextFormatter;
use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionResults;
use Patchline\Detection\DiffParser;
use Patchline\Detection\DiffSection;
use Patchline\Detection\DryRunDetector;
use Patchline\Detection\FilteredDiff;
use Patchline\Detection\PatchRun;
use Patchline\Detection\SystemPatchBinary;
use Patchline\Download\CredentialsResolver;
use Patchline\Download\DownloadResult;
use Patchline\Download\PatchDownloader;
use Patchline\Infrastructure\GuzzleHttpClient;
use Patchline\Infrastructure\HttpClient;
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
use Patchline\Queue\QueueReader;
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
use Patchline\Rewind\QueueRewinder;
use Patchline\Rewind\RewindOutcome;
use Patchline\Rewind\RewindResult;
use Patchline\Rewind\RewindStep;
use Patchline\Tests\Double\FakeHttpClient;
use Patchline\Tests\Double\FrozenClock;
use Patchline\Tests\Support\FixtureStore;
use Patchline\Tests\Support\LocalServer;
use Patchline\Update\ReleaseFeed;
use Patchline\Update\ReleaseFeedParser;
use Patchline\Update\UpdateAvailability;
use Patchline\Update\UpdateChecker;
use Patchline\Update\UpdateNotice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PatchlineCommand::class)]
#[CoversClass(Container::class)]
#[UsesClass(Config::class)]
#[UsesClass(AnalysisInput::class)]
#[UsesClass(Analyzer::class)]
#[UsesClass(Application::class)]
#[UsesClass(Area::class)]
#[UsesClass(CloudPatchesSource::class)]
#[UsesClass(CommandResult::class)]
#[UsesClass(ComponentResolver::class)]
#[UsesClass(ConfigResolver::class)]
#[UsesClass(ConsoleApplication::class)]
#[UsesClass(CredentialsResolver::class)]
#[UsesClass(DependencyGraph::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(DetectionResults::class)]
#[UsesClass(DiffParser::class)]
#[UsesClass(DiffSection::class)]
#[UsesClass(DownloadResult::class)]
#[UsesClass(DryRunDetector::class)]
#[UsesClass(FailOn::class)]
#[UsesClass(FilteredDiff::class)]
#[UsesClass(FormattedOutput::class)]
#[UsesClass(GithubFormatter::class)]
#[UsesClass(GuzzleHttpClient::class)]
#[UsesClass(HotfixDirSource::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(Inspection::class)]
#[UsesClass(Inspector::class)]
#[UsesClass(Installation::class)]
#[UsesClass(InstallationReader::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(JsonFormatter::class)]
#[UsesClass(LoadedRegistry::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(PackageVersion::class)]
#[UsesClass(ParseResult::class)]
#[UsesClass(PatchDownloader::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(PatchReport::class)]
#[UsesClass(PatchRun::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(QueueEntryDiff::class)]
#[UsesClass(QueueReader::class)]
#[UsesClass(QueueRewinder::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryLoader::class)]
#[UsesClass(RegistryParser::class)]
#[UsesClass(RegistryPatch::class)]
#[UsesClass(ReleaseFeed::class)]
#[UsesClass(ReleaseFeedParser::class)]
#[UsesClass(Report::class)]
#[UsesClass(ReportSummary::class)]
#[UsesClass(RewindCommand::class)]
#[UsesClass(RewindOutcome::class)]
#[UsesClass(RewindResult::class)]
#[UsesClass(RewindStep::class)]
#[UsesClass(RewindTextRenderer::class)]
#[UsesClass(SourceResult::class)]
#[UsesClass(StatusCommand::class)]
#[UsesClass(StreamConsoleOutput::class)]
#[UsesClass(Style::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(SystemPatchBinary::class)]
#[UsesClass(TextFormatter::class)]
#[UsesClass(UpdateAvailability::class)]
#[UsesClass(UpdateChecker::class)]
#[UsesClass(UpdateNotice::class)]
final class UpdateNoticeTest extends TestCase
{
    private const ENABLED = ['PATCHLINE_NO_UPDATE_CHECK' => '0'];

    private static LocalServer $server;

    private ?FixtureStore $store = null;
    private FakeHttpClient $http;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalServer::start(__DIR__ . '/../../Fixtures/http/e2e-router.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        $this->http = (new FakeHttpClient())->respond(Config::DEFAULT_UPDATE_URL, 200, $this->feed('0.0.2'));
    }

    protected function tearDown(): void
    {
        $this->store?->remove();
        $this->store = null;
    }

    public function test_it_prints_the_update_notice_on_stderr_after_the_status_report(): void
    {
        $store = $this->createStore('fully-patched');

        $result = $store->run(['status'], self::ENABLED, $this->container($this->http));

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('patchline 0.0.2 is available (you have 0.0.1).', $result['stderr']);
        self::assertStringContainsString('pushonltd/magento-patchline:^0.0.2', $result['stderr']);
        self::assertStringNotContainsString('is available', $result['stdout']);
    }

    public function test_it_prints_the_update_notice_as_a_github_notice_on_stdout_with_the_github_format(): void
    {
        $store = $this->createStore('fully-patched');

        $result = $store->run(['status', '--format=github'], self::ENABLED, $this->container($this->http));

        self::assertStringContainsString(
            '::notice title=Patchline update::patchline 0.0.2 is available',
            $result['stdout'],
        );
        self::assertSame('', $result['stderr']);
    }

    public function test_it_keeps_the_exit_code_and_the_json_stdout_unchanged_when_an_update_is_available(): void
    {
        $store = $this->createStore('fully-patched');
        $without = $store->run(['status', '--format=json']);

        $with = $store->run(['status', '--format=json'], self::ENABLED, $this->container($this->http));

        self::assertSame($without['exitCode'], $with['exitCode']);
        self::assertSame($without['stdout'], $with['stdout']);
        self::assertNotSame('', $with['stderr']);
    }

    public function test_it_prints_no_notice_when_the_update_check_is_disabled(): void
    {
        $store = $this->createStore('fully-patched');

        $result = $store->run(['status', '--no-update-check'], self::ENABLED, $this->container($this->http));

        self::assertSame('', $result['stderr']);
        self::assertSame([], $this->http->requests);
    }

    public function test_it_never_checks_for_updates_during_rewind(): void
    {
        $store = $this->createStore('rewind-applied');

        $store->runRaw(['rewind', '--dry-run'], self::ENABLED, $this->container($this->http));

        self::assertSame([], $this->http->requests);
    }

    public function test_it_prints_no_notice_when_the_command_fails_with_a_usage_or_runtime_error(): void
    {
        $store = $this->createStore('fully-patched');
        $usage = $store->run(['status', '--format=bogus'], self::ENABLED, $this->container($this->http));
        unlink($store->registry());

        $runtime = $store->run(['status'], self::ENABLED, $this->container($this->http));

        self::assertSame(2, $usage['exitCode']);
        self::assertSame(3, $runtime['exitCode']);
        self::assertStringNotContainsString('is available', $usage['stderr'] . $runtime['stderr']);
        self::assertSame([], $this->http->requests);
    }

    public function test_it_prints_the_notice_and_keeps_exit_code_1_when_the_report_has_findings(): void
    {
        $store = $this->createStore('nothing-patched');

        $result = $store->run(['status'], self::ENABLED, $this->container($this->http));

        self::assertSame(1, $result['exitCode'], $result['stderr']);
        self::assertStringContainsString('patchline 0.0.2 is available', $result['stderr']);
    }

    public function test_it_keeps_the_exit_code_when_the_update_check_fails_unexpectedly(): void
    {
        $store = $this->createStore('fully-patched');
        $throwing = new class implements HttpClient {
            public function get(string $url, array $headers = []): HttpResponse
            {
                throw new LogicException('boom');
            }
        };

        $result = $store->run(['status'], self::ENABLED, $this->container($throwing));

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame('', $result['stderr']);
    }

    public function test_it_makes_no_update_request_from_the_default_test_helpers(): void
    {
        $store = $this->createStore('fully-patched');

        $result = $store->run(['status'], [], $this->container($this->http));

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame([], $this->http->requests);
    }

    private function container(HttpClient $updateHttp): Container
    {
        return new Container(
            clock: new FrozenClock(new DateTimeImmutable('2026-10-29 12:00:00')),
            updateHttp: $updateHttp,
            version: '0.0.1',
            pharPath: '',
        );
    }

    private function createStore(string $fixture): FixtureStore
    {
        $this->store = FixtureStore::create($fixture, self::$server->baseUrl());

        return $this->store;
    }

    private function feed(string $version): string
    {
        return (string) json_encode(['packages' => [PackageVersion::PACKAGE => [['version' => $version]]]]);
    }
}
