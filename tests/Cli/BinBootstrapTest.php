<?php

declare(strict_types=1);

namespace Patchline\Tests\Cli;

use Patchline\Tests\Double\TempDirectory;
use Patchline\Tests\Support\CliStore;
use Patchline\Tests\Support\PhpProcess;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Exercises bin/patchline itself, from a copy placed in a throwaway project layout.
 */
#[CoversNothing]
final class BinBootstrapTest extends TestCase
{
    private ?TempDirectory $project = null;

    private ?CliStore $store = null;

    protected function setUp(): void
    {
        $override = getenv('PATCHLINE_BIN');
        if ($override === false || $override === '') {
            return;
        }

        self::markTestSkipped('bin/patchline is not the binary under test.');
    }

    protected function tearDown(): void
    {
        $this->project?->remove();
        $this->store?->remove();
        $this->project = null;
        $this->store = null;
    }

    public function test_it_prints_the_json_document_without_php_deprecation_notices_on_stdout(): void
    {
        $this->store = CliStore::create(true);
        $bin = $this->installBin(
            '<?php trigger_error("Deprecated by an old library", E_USER_DEPRECATED);'
            . ' require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';',
        );

        $result = PhpProcess::run([
            '-d', 'display_errors=1', '-d', 'error_reporting=-1',
            $bin, 'status', '--format=json', '--root=' . $this->store->root(),
            '--registry=' . $this->store->registry(),
        ]);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertIsArray(json_decode($result['stdout'], true), $result['stdout']);
        self::assertStringNotContainsString('Deprecated', $result['stdout']);
    }

    public function test_it_fails_with_a_clear_message_when_no_composer_autoloader_is_found(): void
    {
        $bin = $this->installBin(null);

        $result = PhpProcess::run([$bin, '--version']);

        self::assertSame(3, $result['exitCode']);
        self::assertStringContainsString('composer install', $result['stderr']);
        self::assertSame('', $result['stdout']);
    }

    /**
     * Copies bin/patchline to <tmp>/a/b/c/bin/patchline; the stub autoloader (if any) goes to <tmp>/a/b/c/vendor.
     */
    private function installBin(?string $autoloadStub): string
    {
        $this->project = TempDirectory::create();
        $base = $this->project->path('a/b/c');
        mkdir($base . '/bin', 0777, true);
        copy(dirname(__DIR__, 2) . '/bin/patchline', $base . '/bin/patchline');
        if ($autoloadStub !== null) {
            mkdir($base . '/vendor');
            file_put_contents($base . '/vendor/autoload.php', $autoloadStub);
        }

        return $base . '/bin/patchline';
    }
}
