<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Cli;

use DateTimeImmutable;
use LogicException;
use Patchline\Cli\Config;
use Patchline\Cli\Console\PatchlineCommand;
use Patchline\Cli\Container;
use Patchline\Infrastructure\HttpClient;
use Patchline\Infrastructure\HttpResponse;
use Patchline\PackageVersion;
use Patchline\Tests\Double\FakeHttpClient;
use Patchline\Tests\Double\FrozenClock;
use Patchline\Tests\Support\FixtureStore;
use Patchline\Tests\Support\LocalServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PatchlineCommand::class)]
#[CoversClass(Container::class)]
#[UsesClass(Config::class)]
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
