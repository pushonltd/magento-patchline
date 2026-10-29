<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Workflow;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class WorkflowPolicyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function test_the_zizmor_workflow_fails_the_build_on_any_finding(): void
    {
        $workflow = $this->read('.github/workflows/zizmor.yml');

        self::assertMatchesRegularExpression('/^\s*persona:\s*pedantic\s*$/m', $workflow);
        self::assertMatchesRegularExpression('/^\s*advanced-security:\s*false\b/m', $workflow);
        self::assertMatchesRegularExpression('/^\s*annotations:\s*true\b/m', $workflow);
        self::assertStringContainsString('zizmorcore/zizmor-action@', $workflow);
    }

    #[DataProvider('workflowFiles')]
    public function test_every_granted_permission_has_a_comment_explaining_it(string $file): void
    {
        $contents = $this->read($file);

        preg_match_all('/^\s+[a-z-]+:\s*(?:read|write)\b(.*)$/m', $contents, $matches);

        foreach ($matches[0] as $index => $line) {
            if (preg_match('/^\s+(?:token|fetch-depth):/', $line) === 1) {
                continue;
            }
            self::assertMatchesRegularExpression('/^\s*#\s*\S+/', $matches[1][$index], 'No comment on: ' . trim($line));
        }
    }

    public function test_dependabot_updates_actions_and_composer_with_a_cooldown(): void
    {
        $dependabot = $this->read('.github/dependabot.yml');

        self::assertMatchesRegularExpression('/package-ecosystem:\s*"?github-actions"?/', $dependabot);
        self::assertMatchesRegularExpression('/package-ecosystem:\s*"?composer"?/', $dependabot);
        self::assertSame(4, preg_match_all('/^\s+interval:\s*"?weekly"?/m', $dependabot));
        self::assertSame(4, preg_match_all('/^\s+cooldown:\s*\n\s+default-days:\s*[1-9]\d*/m', $dependabot));
        self::assertSame(4, preg_match_all('/^\s+groups:/m', $dependabot));
    }

    #[DataProvider('toolDirectories')]
    public function test_dependabot_watches_the_composer_tool_directories(string $directory): void
    {
        $dependabot = $this->read('.github/dependabot.yml');

        self::assertMatchesRegularExpression(
            '/package-ecosystem:\s*"?composer"?\s*\n\s+directory:\s*"?' . preg_quote($directory, '/') . '"?\s*$/m',
            $dependabot,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function toolDirectories(): array
    {
        return ['box' => ['/tools/box'], 'infection' => ['/tools/infection']];
    }

    public function test_the_prefer_lowest_job_restricts_vendor_deprecations_with_its_own_phpunit_config(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');
        $main = $this->read('phpunit.xml.dist');
        $lowest = $this->read('phpunit.prefer-lowest.xml.dist');

        self::assertStringContainsString('restrictDeprecations="true"', $lowest);
        self::assertStringNotContainsString('restrictDeprecations', $main);
        self::assertStringNotContainsString('ignoreIndirectDeprecations', $main);
        self::assertStringContainsString('failOnDeprecation="true"', $lowest);
        self::assertStringContainsString('failOnWarning="true"', $lowest);
        self::assertMatchesRegularExpression('/-c phpunit\.prefer-lowest\.xml\.dist/', $ci);
        self::assertSame(1, substr_count($ci, 'phpunit.prefer-lowest.xml.dist'));
    }

    public function test_the_mutation_job_runs_infection_on_php_8_5_with_pcov_and_uploads_the_log(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');

        self::assertMatchesRegularExpression('/^  mutation:\s*$/m', $ci);
        $job = substr($ci, (int) strpos($ci, "\n  mutation:"));
        self::assertMatchesRegularExpression('/php-version:\s*"8\.5"\s*\n\s+coverage:\s*pcov/', $job);
        self::assertMatchesRegularExpression('/composer (update|install) [^\n]*--working-dir=tools\/infection/', $job);
        self::assertMatchesRegularExpression('/run:\s*composer mutation\s*$/m', $job);
        self::assertMatchesRegularExpression('/actions\/upload-artifact@[0-9a-f]{40}/', $job);
        self::assertMatchesRegularExpression('/path:\s*build\/infection\/infection\.log/', $job);
        self::assertMatchesRegularExpression('/persist-credentials:\s*false/', $job);
    }

    public function test_the_release_workflow_uses_no_dependency_cache(): void
    {
        $release = $this->read('.github/workflows/release.yml');

        self::assertDoesNotMatchRegularExpression('/\bcache\s*:/i', $release);
        self::assertDoesNotMatchRegularExpression('/actions\/cache/', $release);
        self::assertDoesNotMatchRegularExpression('/COMPOSER_CACHE/', $release);
    }

    #[DataProvider('workflowFiles')]
    public function test_no_workflow_uses_pull_request_target_or_workflow_run_triggers(string $file): void
    {
        $contents = $this->read($file);

        self::assertDoesNotMatchRegularExpression('/^\s*(?:pull_request_target|workflow_run)\s*:/m', $contents);
    }

    #[DataProvider('workflowFiles')]
    public function test_every_action_reference_is_pinned_to_a_full_commit_sha(string $file): void
    {
        preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/m', $this->read($file), $matches, PREG_SET_ORDER);

        self::assertNotEmpty($matches);
        foreach ($matches as $match) {
            self::assertMatchesRegularExpression('/@[0-9a-f]{40}$/', $match[1], $match[1] . ' is not pinned');
            self::assertMatchesRegularExpression('/^\s*#\s*v?\d+(\.\d+)*/', $match[2], $match[1] . ' has no version');
        }
    }

    #[DataProvider('workflowFiles')]
    public function test_every_workflow_declares_empty_top_level_permissions(string $file): void
    {
        self::assertMatchesRegularExpression('/^permissions:\s*\{\}\s*$/m', $this->read($file));
    }

    public function test_the_test_matrix_covers_php_8_2_to_8_5_on_linux_only(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');

        foreach (['8.2', '8.3', '8.4', '8.5'] as $version) {
            self::assertMatchesRegularExpression('/php:\s*\[[^\]]*"' . preg_quote($version, '/') . '"/', $ci);
        }
        self::assertMatchesRegularExpression('/os:\s*\[ubuntu-latest\]/', $ci);
        self::assertStringNotContainsString('macos', $ci);
    }

    public function test_the_test_matrix_includes_a_prefer_lowest_run_on_php_8_2(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');

        self::assertMatchesRegularExpression('/php:\s*"8\.2"\s*\n\s*dependencies:\s*lowest/', $ci);
        self::assertStringContainsString('--prefer-lowest', $ci);
    }

    public function test_no_workflow_or_package_targets_the_end_of_life_php_8_1(): void
    {
        self::assertMatchesRegularExpression('/"php":\s*">=8\.2"/', $this->read('composer.json'));
        foreach (['ci.yml', 'release.yml', 'zizmor.yml'] as $workflow) {
            self::assertDoesNotMatchRegularExpression('/"8\.1"/', $this->read('.github/workflows/' . $workflow));
        }
    }

    public function test_the_coverage_job_fails_below_95_percent_line_coverage(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');
        $composer = $this->read('composer.json');

        self::assertMatchesRegularExpression('/coverage:\s*pcov/', $ci);
        self::assertMatchesRegularExpression('/run:\s*composer test:coverage\s*$/m', $ci);
        self::assertStringContainsString('GITHUB_STEP_SUMMARY', $ci);
        self::assertMatchesRegularExpression('#tools/coverage-check\.php build/coverage/clover\.xml 95#', $composer);
    }

    public function test_the_cli_suite_passes_against_the_phar_in_ci(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');

        self::assertMatchesRegularExpression('/run:\s*composer build\s*$/m', $ci);
        self::assertMatchesRegularExpression('/PATCHLINE_BIN:\s*dist\/patchline\.phar\b/', $ci);
        self::assertSame(2, preg_match_all('/run:\s*composer test:cli:phar\s*$/m', $ci));
        self::assertMatchesRegularExpression('/composer (update|install) [^\n]*--working-dir=tools\/box/', $ci);
        self::assertStringContainsString('-c tests/Build/phpunit.xml', $ci);
    }

    public function test_the_phar_is_tested_on_the_lowest_and_highest_supported_php(): void
    {
        $ci = $this->read('.github/workflows/ci.yml');

        self::assertMatchesRegularExpression('/name: dist\b/', $ci);
        self::assertMatchesRegularExpression('/phar:\s*\n(?:.*\n)*?\s+php:\s*\["8\.2",\s*"8\.5"\]/', $ci);
    }

    public function test_the_release_workflow_attaches_the_phar_and_its_checksum_and_attests_the_phar(): void
    {
        $release = $this->read('.github/workflows/release.yml');

        self::assertMatchesRegularExpression('/tags:\s*\n\s*-\s*"v\*"/', $release);
        self::assertMatchesRegularExpression(
            '/actions\/attest-build-provenance@.*\n\s+with:\s*\n\s+subject-path:\s*dist\/patchline\.phar\s*$/m',
            $release,
        );
        self::assertMatchesRegularExpression(
            '/gh release create .*dist\/patchline\.phar\b.*dist\/patchline\.phar\.sha256/s',
            $release,
        );
        self::assertMatchesRegularExpression(
            '/gh release create .*dist\/patchline\.phar\.sha256\s+dist\/install\.sh\b/s',
            $release,
        );
        self::assertMatchesRegularExpression('/cp install\.sh dist\/install\.sh/', $release);
        self::assertMatchesRegularExpression('/--version="\$\{?RELEASE_TAG\}?"/', $release);
        self::assertMatchesRegularExpression('/PATCHLINE_BIN:\s*dist\/patchline\.phar\b/', $release);
        self::assertMatchesRegularExpression('/run:\s*composer test:cli:phar\s*$/m', $release);
    }

    public function test_the_phar_cli_suite_excludes_only_the_tests_of_bin_patchline_itself(): void
    {
        self::assertMatchesRegularExpression(
            '/"test:cli:phar":\s*"phpunit --testsuite=cli --exclude-group=bin-source"/',
            $this->read('composer.json'),
        );
    }

    public function test_no_test_skips_itself(): void
    {
        $offenders = [];
        $tests = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/tests'));
        foreach ($tests as $file) {
            if (
                !$file instanceof \SplFileInfo
                || $file->getExtension() !== 'php'
                || str_contains($file->getPathname(), '/Fixtures/')
            ) {
                continue;
            }
            if (!str_contains((string) file_get_contents($file->getPathname()), 'markTest' . 'Skipped(')) {
                continue;
            }

            $offenders[] = $file->getFilename();
        }

        self::assertSame(
            [],
            $offenders,
            'A skipped test looks green; exclude it with a group in the job that cannot run it.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function workflowFiles(): array
    {
        $files = [];
        foreach (['ci', 'release', 'zizmor'] as $name) {
            $files[$name] = ['.github/workflows/' . $name . '.yml'];
        }

        return $files;
    }

    private function read(string $relative): string
    {
        $path = self::ROOT . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
