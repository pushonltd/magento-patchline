<?php

declare(strict_types=1);

namespace Patchline\Tests\Support;

use Patchline\Cli\Application;
use Patchline\Cli\Container;
use Patchline\Tests\Double\TempDirectory;
use RuntimeException;

/**
 * A copy of a store from tests/Fixtures/stores in a temp directory, plus a registry (e2e.json with the local
 * server's URL and the real sha256 of every diff) and a cache directory outside the store.
 */
final class FixtureStore
{
    public const A = '249-2026-01-001-CE';
    public const B = '249-2026-02-001-CE';
    public const C = '249-2026-03-001-CE';
    public const D = '249-2026-04-001-CE';

    public const COMPOSER_AUTH = '{"http-basic":{"repo.magento.com":{"username":"e2e-user","password":"e2e-secret"}}}';

    private function __construct(private readonly TempDirectory $directory)
    {
    }

    public static function create(string $fixture, string $serverUrl): self
    {
        $fixtures = __DIR__ . '/../Fixtures';
        $store = new self(TempDirectory::create());
        self::copyTree($fixtures . '/stores/' . $fixture, $store->root());
        mkdir($store->directory->path('work'));
        file_put_contents($store->registry(), self::registryJson($fixtures, $serverUrl));

        return $store;
    }

    public function root(): string
    {
        return $this->directory->path('store');
    }

    public function registry(): string
    {
        return $this->directory->path('work/registry.json');
    }

    public function cacheDir(): string
    {
        return $this->directory->path('work/cache');
    }

    public function remove(): void
    {
        $this->directory->remove();
    }

    /**
     * Runs patchline as a process against this store and the local registry, with no credentials unless given.
     *
     * @param list<string> $arguments command and options
     * @param array<string, string> $environment
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public function patchline(array $arguments, array $environment = [], ?string $binary = null): array
    {
        return PhpProcess::run(
            [$binary ?? self::binary(), ...$this->arguments($arguments)],
            null,
            $this->environment($environment),
        );
    }

    /**
     * Runs the Application in this process (so coverage and mutation testing see it), with the same arguments
     * and environment as patchline().
     *
     * @param list<string> $arguments command and options
     * @param array<string, string> $environment
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public function run(array $arguments, array $environment = [], ?Container $container = null): array
    {
        return $this->runWith($this->arguments($arguments), $environment, $container);
    }

    /**
     * @param list<string> $arguments complete argument list
     * @param array<string, string> $environment
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runWith(array $arguments, array $environment, ?Container $container): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Cannot open in-memory streams.');
        }

        $exitCode = (new Application($container ?? new Container()))->run(
            $arguments,
            $this->environment($environment),
            $this->root(),
            $stdout,
            $stderr,
        );

        return [
            'stdout' => self::contents($stdout),
            'stderr' => self::contents($stderr),
            'exitCode' => $exitCode,
        ];
    }

    /**
     * Like patchline() for commands that do not take the registry options: only --root and --no-ansi are appended.
     *
     * @param list<string> $arguments command and options
     * @param array<string, string> $environment
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public function patchlineRaw(array $arguments, array $environment = [], ?string $binary = null): array
    {
        return PhpProcess::run(
            [$binary ?? self::binary(), ...$this->rawArguments($arguments)],
            null,
            $this->environment($environment),
        );
    }

    /**
     * Like run() for commands that do not take the registry options.
     *
     * @param list<string> $arguments command and options
     * @param array<string, string> $environment
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public function runRaw(array $arguments, array $environment = [], ?Container $container = null): array
    {
        return $this->runWith($this->rawArguments($arguments), $environment, $container);
    }

    /** @return array<string, string> relative path => sha256 of every file in the store */
    public function snapshot(): array
    {
        $files = [];
        $this->collect($this->root(), '', $files);
        ksort($files);

        return $files;
    }

    public static function binary(): string
    {
        $override = getenv('PATCHLINE_BIN');

        return $override !== false && $override !== '' ? $override : dirname(__DIR__, 2) . '/bin/patchline';
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function rawArguments(array $arguments): array
    {
        return [...$arguments, '--root=' . $this->root(), '--no-ansi'];
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function arguments(array $arguments): array
    {
        return [
            ...$arguments,
            '--root=' . $this->root(),
            '--registry=' . $this->registry(),
            '--cache-dir=' . $this->cacheDir(),
            '--no-ansi',
        ];
    }

    /**
     * @param array<string, string> $environment
     * @return array<string, string>
     */
    private function environment(array $environment): array
    {
        $home = $this->directory->path('work/home');
        if (!is_dir($home)) {
            mkdir($home);
        }

        return [
            'PATCHLINE_ALLOW_INSECURE_REGISTRY' => '1',
            'PATCHLINE_NO_UPDATE_CHECK' => '1',
            'HOME' => $home,
            'COMPOSER_HOME' => $home,
            'COMPOSER_AUTH' => '',
            'GITHUB_ACTIONS' => '',
            'GITHUB_STEP_SUMMARY' => '',
            ...$environment,
        ];
    }

    /** @param resource $stream */
    private static function contents($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private static function registryJson(string $fixtures, string $serverUrl): string
    {
        $json = (string) file_get_contents($fixtures . '/registry/e2e.json');
        $registry = json_decode(
            str_replace('http://127.0.0.1:{port}', $serverUrl, $json),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (!is_array($registry) || !is_array($registry['patches'] ?? null)) {
            throw new RuntimeException('e2e.json has no patches.');
        }
        $patches = [];
        foreach ($registry['patches'] as $id => $patch) {
            if (!is_array($patch) || !is_string($patch['file_name'] ?? null)) {
                throw new RuntimeException('e2e.json patch ' . $id . ' has no file_name.');
            }
            $patch['sha256'] = hash_file('sha256', $fixtures . '/diffs/' . $patch['file_name']);
            $patches[$id] = $patch;
        }
        $registry['patches'] = $patches;

        return json_encode($registry, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /** @param array<string, string> $files */
    private function collect(string $directory, string $prefix, array &$files): void
    {
        foreach (array_diff((array) scandir($directory), ['.', '..']) as $name) {
            $path = $directory . '/' . $name;
            if (is_dir($path)) {
                $this->collect($path, $prefix . $name . '/', $files);
                continue;
            }
            $files[$prefix . $name] = (string) hash_file('sha256', $path);
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (array_diff((array) scandir($from), ['.', '..']) as $name) {
            $source = $from . '/' . $name;
            is_dir($source) ? self::copyTree($source, $to . '/' . $name) : copy($source, $to . '/' . $name);
        }
    }
}
