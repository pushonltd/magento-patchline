<?php

declare(strict_types=1);

namespace Patchline\Tests\Cli;

use Patchline\Tests\Double\TempDirectory;
use Patchline\Tests\Support\FixtureStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RewindTest extends TestCase
{
    private ?FixtureStore $store = null;
    private ?TempDirectory $emptyPath = null;

    protected function tearDown(): void
    {
        $this->store?->remove();
        $this->emptyPath?->remove();
    }

    public function test_rewind_reverses_the_applied_queue_and_exits_zero(): void
    {
        $store = $this->store();

        $result = $store->patchlineRaw(['rewind']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame("one\n", file_get_contents($store->root() . '/vendor/magento/module-a/a.php'));
        self::assertStringContainsString('Reversed 3 patches.', $result['stdout']);
    }

    public function test_rewind_exits_three_when_the_patch_program_is_missing(): void
    {
        $store = $this->store();
        $this->emptyPath = TempDirectory::create();

        $result = $store->patchlineRaw(['rewind'], ['PATH' => $this->emptyPath->path('')]);

        self::assertSame(3, $result['exitCode'], $result['stdout']);
        self::assertStringContainsString('"patch" program is not installed', $result['stderr']);
    }

    public function test_rewind_rejects_status_only_options(): void
    {
        $result = $this->store()->patchline(['rewind']);

        self::assertSame(2, $result['exitCode']);
        self::assertStringContainsString('--registry', $result['stderr']);
    }

    public function test_rewind_is_listed_in_help(): void
    {
        $result = $this->store()->patchlineRaw(['rewind', '--help']);

        self::assertSame(0, $result['exitCode'], $result['stderr']);
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
}
