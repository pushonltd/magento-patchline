<?php

declare(strict_types=1);

namespace Patchline\Tests\Integration\Install;

use Patchline\Tests\Support\LocalServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class InstallScriptTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../../install.sh';

    private LocalServer $server;
    private string $work;

    protected function setUp(): void
    {
        $this->server = LocalServer::start(dirname(__DIR__, 2) . '/Fixtures/http/release-router.php');
        $this->work = sys_get_temp_dir() . '/patchline-install-' . bin2hex(random_bytes(6));
        mkdir($this->work . '/bin', 0777, true);
        mkdir($this->work . '/ghbin', 0777, true);
        mkdir($this->work . '/target', 0777, true);
        $this->linkTools();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        exec('rm -rf ' . escapeshellarg($this->work));
    }

    public function test_it_installs_the_latest_phar_and_makes_it_executable(): void
    {
        $result = $this->install('ok');

        self::assertSame(0, $result['exit'], $result['err']);
        self::assertFileExists($this->target());
        self::assertTrue(is_executable($this->target()));
        self::assertStringContainsString('patchline latest', $result['out']);
        self::assertStringContainsString('status', $result['out']);
        self::assertFileDoesNotExist($this->target() . '.part');
    }

    public function test_it_installs_a_pinned_version_when_patchline_version_is_set(): void
    {
        $result = $this->install('ok', ['PATCHLINE_VERSION' => '0.0.3']);

        self::assertSame(0, $result['exit'], $result['err']);
        self::assertStringContainsString('patchline v0.0.3', $result['out']);
    }

    public function test_it_accepts_a_pinned_version_with_the_leading_v_as_a_flag(): void
    {
        $result = $this->install('ok', [], ['--version=v0.0.4']);

        self::assertSame(0, $result['exit'], $result['err']);
        self::assertStringContainsString('patchline v0.0.4', $result['out']);
    }

    public function test_it_refuses_to_install_and_leaves_no_file_when_the_checksum_does_not_match(): void
    {
        $result = $this->install('badsum');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('Checksum mismatch', $result['err']);
        self::assertSame([], $this->targetFiles());
    }

    public function test_it_installs_to_the_given_path_and_replaces_an_existing_phar_there(): void
    {
        $path = $this->work . '/target/custom/patchline';
        mkdir(dirname($path));
        file_put_contents($path, 'old');

        $result = $this->install('ok', ['PATCHLINE_INSTALL_PATH' => $path]);

        self::assertSame(0, $result['exit'], $result['err']);
        self::assertStringContainsString('patchline', (string) file_get_contents($path));
        self::assertNotSame('old', file_get_contents($path));
        self::assertSame(['patchline'], $this->listDirectory(dirname($path)));
    }

    public function test_it_installs_to_the_path_flag(): void
    {
        $path = $this->work . '/target/flagged.phar';

        $result = $this->install('ok', [], ['--path=' . $path]);

        self::assertSame(0, $result['exit'], $result['err']);
        self::assertFileExists($path);
    }

    public function test_it_fails_with_a_clear_message_when_the_target_directory_is_not_writable(): void
    {
        $dir = $this->work . '/target/readonly';
        mkdir($dir, 0555);

        $result = $this->install('ok', ['PATCHLINE_INSTALL_PATH' => $dir . '/patchline.phar']);

        chmod($dir, 0755);
        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('Cannot write to', $result['err']);
    }

    public function test_it_refuses_to_install_when_the_checksum_file_is_empty_or_malformed(): void
    {
        foreach (['emptysum', 'malformed'] as $scenario) {
            $result = $this->install($scenario);

            self::assertNotSame(0, $result['exit'], $scenario);
            self::assertStringContainsString('empty or malformed', $result['err'], $scenario);
            self::assertSame([], $this->targetFiles(), $scenario);
        }
    }

    public function test_it_runs_gh_attestation_verify_when_gh_is_on_the_path(): void
    {
        $this->fakeGh(authenticated: true, verifies: true);

        $result = $this->install('ok');

        self::assertSame(0, $result['exit'], $result['err']);
        $log = (string) file_get_contents($this->work . '/gh.log');
        self::assertStringContainsString('attestation verify', $log);
        self::assertStringContainsString('-R pushonltd/magento-patchline', $log);
        self::assertStringContainsString(
            '--signer-workflow pushonltd/magento-patchline/.github/workflows/release.yml',
            $log,
        );
        self::assertFileExists($this->target());
    }

    public function test_it_fails_when_gh_attestation_verify_fails(): void
    {
        $this->fakeGh(authenticated: true, verifies: false);

        $result = $this->install('ok');

        self::assertNotSame(0, $result['exit']);
        self::assertStringContainsString('provenance verification failed', $result['err']);
        self::assertSame([], $this->targetFiles());
    }

    public function test_it_installs_and_says_provenance_was_skipped_when_gh_is_not_authenticated(): void
    {
        $this->fakeGh(authenticated: false, verifies: false);

        $result = $this->install('ok');

        self::assertSame(0, $result['exit'], $result['err']);
        self::assertStringContainsString('gh auth login', $result['out']);
        self::assertStringNotContainsString('attestation', (string) file_get_contents($this->work . '/gh.log'));
        self::assertFileExists($this->target());
    }

    public function test_it_installs_and_says_provenance_was_not_checked_when_gh_is_not_installed(): void
    {
        $result = $this->install('ok');

        self::assertSame(0, $result['exit'], $result['err']);
        self::assertStringContainsString('Provenance not checked', $result['out']);
        self::assertFileExists($this->target());
    }

    public function test_it_runs_nothing_when_the_script_is_truncated(): void
    {
        $lines = file(self::SCRIPT);
        self::assertIsArray($lines);
        $truncated = implode('', array_slice($lines, 0, -1));

        $result = $this->execute(['bash'], [], $truncated);

        self::assertSame(0, $result['exit'], $result['err']);
        self::assertSame('', $result['out']);
        self::assertSame([], $this->targetFiles());
    }

    private function target(): string
    {
        return $this->work . '/target/patchline.phar';
    }

    /**
     * @return list<string>
     */
    private function targetFiles(): array
    {
        return $this->listDirectory($this->work . '/target', ['custom', 'readonly']);
    }

    /**
     * @param list<string> $ignore
     * @return list<string>
     */
    private function listDirectory(string $dir, array $ignore = []): array
    {
        $names = [];
        foreach ((array) scandir($dir) as $name) {
            if (!is_string($name) || in_array($name, ['.', '..', ...$ignore], true)) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $args
     * @return array{exit: int, out: string, err: string}
     */
    private function install(string $scenario, array $env = [], array $args = []): array
    {
        $env += ['PATCHLINE_DOWNLOAD_URL' => $this->server->baseUrl() . '/' . $scenario];

        return $this->execute(['bash', self::SCRIPT, ...$args], $env, null);
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{exit: int, out: string, err: string}
     */
    private function execute(array $command, array $env, ?string $stdin): array
    {
        $env += [
            'PATH' => $this->work . '/ghbin:' . $this->work . '/bin',
            'HOME' => $this->work,
            'GH_LOG' => $this->work . '/gh.log',
        ];
        $env += ['PATCHLINE_INSTALL_PATH' => $this->target()];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->work,
            $env,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    private function fakeGh(bool $authenticated, bool $verifies): void
    {
        $script = "#!/bin/sh\necho \"\$*\" >> \"\$GH_LOG\"\n"
            . 'case "$1" in auth) exit ' . ($authenticated ? '0' : '1') . " ;; esac\n"
            . 'case "$1" in attestation) exit ' . ($verifies ? '0' : '1') . " ;; esac\nexit 0\n";
        file_put_contents($this->work . '/ghbin/gh', $script);
        chmod($this->work . '/ghbin/gh', 0755);
        touch($this->work . '/gh.log');
    }

    /** A PATH holding only what the script needs, so a real gh on the host never leaks in. */
    private function linkTools(): void
    {
        $tools = ['bash', 'curl', 'php', 'mktemp', 'mv', 'rm', 'chmod', 'dirname', 'tr', 'sha256sum', 'shasum', 'cat'];
        foreach ($tools as $tool) {
            $found = trim((string) shell_exec('command -v ' . $tool));
            if ($found === '' || $tool === 'php') {
                continue;
            }
            symlink($found, $this->work . '/bin/' . $tool);
        }
        symlink(PHP_BINARY, $this->work . '/bin/php');
    }
}
