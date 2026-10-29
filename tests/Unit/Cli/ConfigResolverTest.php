<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli;

use Patchline\Cli\Application;
use Patchline\Cli\Config;
use Patchline\Cli\ConfigResolver;
use Patchline\Cli\Console\ConsoleApplication;
use Patchline\Cli\Console\PatchlineCommand;
use Patchline\Cli\Console\RewindCommand;
use Patchline\Cli\Container;
use Patchline\Cli\OutputFormat;
use Patchline\Exception\RuntimeFailure;
use Patchline\Exception\UsageException;
use Patchline\Infrastructure\GuzzleHttpClient;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Infrastructure\SymfonyProcessRunner;
use Patchline\PackageVersion;
use Patchline\Tests\Double\TempDirectory;
use Patchline\Tests\Support\CommandInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigResolver::class)]
#[CoversClass(Config::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(Application::class)]
#[UsesClass(ConsoleApplication::class)]
#[UsesClass(PatchlineCommand::class)]
#[UsesClass(RewindCommand::class)]
#[UsesClass(Container::class)]
#[UsesClass(GuzzleHttpClient::class)]
#[UsesClass(SymfonyProcessRunner::class)]
#[UsesClass(PackageVersion::class)]
final class ConfigResolverTest extends TestCase
{
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     */
    private function resolve(array $argv, array $env = [], ?string $cwd = null): Config
    {
        $input = CommandInput::parse($argv);

        return (new ConfigResolver(new LocalFilesystem()))
            ->resolve($input, (string) $input->getFirstArgument(), $env, $cwd ?? $this->dir->path());
    }

    private function writeExtra(string $json): void
    {
        file_put_contents($this->dir->path('composer.json'), '{"extra": {"patchline": ' . $json . '}}');
    }

    public function test_it_prefers_a_command_line_flag_over_an_environment_variable(): void
    {
        $config = $this->resolve(['status', '--format=json'], ['PATCHLINE_FORMAT' => 'junit']);

        self::assertSame(OutputFormat::Json, $config->format());
    }

    public function test_it_prefers_an_environment_variable_over_composer_json_extra(): void
    {
        $this->writeExtra('{"format": "junit", "fail-on": ["missing"]}');

        $config = $this->resolve(['status'], ['PATCHLINE_FORMAT' => 'json']);

        self::assertSame(OutputFormat::Json, $config->format());
        self::assertSame(['missing'], $config->failOnStates());
    }

    public function test_it_reads_options_from_composer_json_extra_patchline(): void
    {
        $this->writeExtra('{"fail-on": ["missing", "cross-check"], "include-entitled": true, "registry": "r.json",'
            . ' "cache-dir": "/c", "no-cache": true}');

        $config = $this->resolve(['sync']);

        self::assertSame(['missing'], $config->failOnStates());
        self::assertTrue($config->failOnCrossCheck());
        self::assertTrue($config->includeEntitled());
        self::assertSame('r.json', $config->registry());
        self::assertSame('/c', $config->cacheDir());
        self::assertTrue($config->noCache());
        self::assertFalse($config->dryRun());
    }

    public function test_it_applies_defaults(): void
    {
        $config = $this->resolve(['status']);

        self::assertSame('status', $config->command());
        self::assertSame($this->dir->path(), $config->root());
        self::assertSame(OutputFormat::Text, $config->format());
        self::assertSame(['missing', 'duplicate', 'misordered'], $config->failOnStates());
        self::assertFalse($config->failOnCrossCheck());
        self::assertNull($config->registry());
        self::assertNull($config->cacheDir());
        self::assertFalse($config->noCache());
        self::assertFalse($config->noAnsi());
    }

    public function test_it_enables_the_update_check_by_default(): void
    {
        self::assertTrue($this->resolve(['status'])->updateCheck());
    }

    public function test_it_disables_the_update_check_with_the_no_update_check_flag(): void
    {
        self::assertFalse($this->resolve(['status', '--no-update-check'])->updateCheck());
    }

    public function test_it_disables_the_update_check_with_patchline_no_update_check(): void
    {
        $config = $this->resolve(['status'], ['PATCHLINE_NO_UPDATE_CHECK' => '1']);

        self::assertFalse($config->updateCheck());
    }

    public function test_it_disables_the_update_check_with_no_update_check_in_extra_patchline(): void
    {
        $this->writeExtra('{"no-update-check": true}');

        self::assertFalse($this->resolve(['status'])->updateCheck());
    }

    public function test_it_lets_the_flag_win_over_the_environment_and_the_environment_over_extra_patchline(): void
    {
        $this->writeExtra('{"no-update-check": true}');

        $envWins = $this->resolve(['status'], ['PATCHLINE_NO_UPDATE_CHECK' => '0']);
        $flagWins = $this->resolve(['status', '--no-update-check'], ['PATCHLINE_NO_UPDATE_CHECK' => '0']);

        self::assertTrue($envWins->updateCheck());
        self::assertFalse($flagWins->updateCheck());
    }

    public function test_it_uses_the_packagist_url_unless_patchline_update_url_is_set(): void
    {
        $default = $this->resolve(['status']);
        $empty = $this->resolve(['status'], ['PATCHLINE_UPDATE_URL' => '']);
        $custom = $this->resolve(['status'], ['PATCHLINE_UPDATE_URL' => 'http://127.0.0.1:1/x.json']);

        self::assertSame(Config::DEFAULT_UPDATE_URL, $default->updateUrl());
        self::assertSame('https://repo.packagist.org/p2/pushonltd/magento-patchline.json', $empty->updateUrl());
        self::assertSame('http://127.0.0.1:1/x.json', $custom->updateUrl());
    }

    public function test_it_reads_booleans_from_the_environment(): void
    {
        $config = $this->resolve(['status'], ['PATCHLINE_NO_CACHE' => 'true', 'PATCHLINE_INCLUDE_ENTITLED' => '0']);

        self::assertTrue($config->noCache());
        self::assertFalse($config->includeEntitled());
    }

    public function test_it_rejects_an_invalid_boolean_in_the_environment(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('PATCHLINE_NO_CACHE');

        $this->resolve(['status'], ['PATCHLINE_NO_CACHE' => 'maybe']);
    }

    public function test_it_defaults_the_format_to_github_inside_github_actions(): void
    {
        $config = $this->resolve(['status'], ['GITHUB_ACTIONS' => 'true']);
        $explicit = $this->resolve(['status', '--format=text'], ['GITHUB_ACTIONS' => 'true']);

        self::assertSame(OutputFormat::Github, $config->format());
        self::assertSame(OutputFormat::Text, $explicit->format());
    }

    public function test_it_rejects_an_empty_value(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('--registry');

        $this->resolve(['status', '--registry=']);
    }

    public function test_it_rejects_an_unknown_state_in_fail_on(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('bogus');

        $this->resolve(['status', '--fail-on=missing,bogus']);
    }

    public function test_it_rejects_an_unknown_format(): void
    {
        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('xml');

        $this->resolve(['status', '--format=xml']);
    }

    public function test_it_rejects_root_in_composer_json_extra(): void
    {
        $this->writeExtra('{"root": "/x"}');

        $this->expectException(UsageException::class);
        $this->expectExceptionMessage('root');

        $this->resolve(['status']);
    }

    public function test_it_expands_a_leading_tilde_in_the_root_option(): void
    {
        mkdir($this->dir->path('store'));

        $config = $this->resolve(['status', '--root=~/store'], ['HOME' => $this->dir->path()], '/nowhere');

        self::assertSame($this->dir->path('store'), $config->root());
    }

    public function test_it_resolves_a_relative_root_against_the_cwd(): void
    {
        mkdir($this->dir->path('sub'));

        $config = $this->resolve(['status', '--root=sub'], []);

        self::assertSame($this->dir->path('sub'), $config->root());
    }

    public function test_it_fails_at_runtime_for_an_unreadable_root(): void
    {
        $this->expectException(RuntimeFailure::class);

        $this->resolve(['status', '--root=missing']);
    }

    public function test_it_accepts_sync_only_flags_for_the_sync_command(): void
    {
        $config = $this->resolve(['sync', '--dry-run', '--adopt', '--prune']);

        self::assertTrue($config->dryRun());
        self::assertTrue($config->adopt());
        self::assertTrue($config->prune());
    }
}
