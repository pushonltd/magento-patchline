<?php

declare(strict_types=1);

namespace Patchline\Tests\Cli;

use Patchline\Tests\Support\FixtureStore;
use Patchline\Tests\Support\LocalServer;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class EndToEndTest extends TestCase
{
    private static LocalServer $server;

    private ?FixtureStore $store = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = LocalServer::start(__DIR__ . '/../Fixtures/http/e2e-router.php');
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

    /**
     * @param array<string, string> $expected patch id => state
     */
    #[DataProvider('statesPerStore')]
    public function test_it_reports_every_state_from_the_architecture_state_table_on_its_fixture_store(
        string $fixture,
        array $expected,
        int $exitCode,
    ): void {
        $this->store = FixtureStore::create($fixture, self::$server->baseUrl());

        $result = $this->store->patchline(['status', '--format=json']);

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
        yield 'entitled on open source' => ['entitled-on-open-source', [
            FixtureStore::A => 'applied', FixtureStore::B => 'applied',
            FixtureStore::C => 'entitled', FixtureStore::D => 'applied',
        ], 0];
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
        $this->store = FixtureStore::create('nothing-patched', self::$server->baseUrl());

        $sync = $this->store->patchline(['sync']);
        $verify = $this->store->patchline(['verify']);

        self::assertSame(0, $sync['exitCode'], $sync['stderr']);
        $hotfixes = array_values(array_filter(
            array_keys($this->store->snapshot()),
            static fn (string $path): bool => str_starts_with($path, 'm2-hotfixes/'),
        ));
        self::assertSame([
            'm2-hotfixes/2026-01-13_249-2026-01-001-CE.patch',
            'm2-hotfixes/2026-02-10_249-2026-02-001-CE.patch',
            'm2-hotfixes/2026-04-14_249-2026-04-001-CE.patch',
        ], $hotfixes);
        self::assertSame(0, $verify['exitCode'], $verify['stdout'] . $verify['stderr']);
        self::assertStringContainsString('Verify passed', $verify['stdout']);
    }

    // phpcs:ignore Generic.Files.LineLength.TooLong -- the method name is the requirement text
    public function test_it_warns_that_adopt_is_needed_when_sync_would_queue_a_dated_file_before_a_hand_copied_prerequisite(): void
    {
        $this->store = FixtureStore::create('hand-copied-prerequisite', self::$server->baseUrl());

        $result = $this->store->patchline(['sync']);

        self::assertStringContainsString(
            'm2-hotfixes/2026-02-10_249-2026-02-001-CE.patch would be applied before m2-hotfixes/hand-copied-a.patch',
            $result['stdout'],
        );
        self::assertStringContainsString('Run sync with --adopt', $result['stdout']);
        self::assertSame(1, $result['exitCode']);
    }

    public function test_it_fixes_a_misordered_store_with_sync_adopt(): void
    {
        $this->store = FixtureStore::create('misordered', self::$server->baseUrl());

        $adopt = $this->store->patchline(['sync', '--adopt']);
        $states = $this->states($this->store->patchline(['status', '--format=json'])['stdout']);

        self::assertSame(0, $adopt['exitCode'], $adopt['stdout'] . $adopt['stderr']);
        self::assertSame('queued', $states[FixtureStore::A]);
        self::assertSame('queued', $states[FixtureStore::B]);
        self::assertFileExists($this->store->root() . '/m2-hotfixes/2026-01-13_249-2026-01-001-CE.patch');
        self::assertFileExists($this->store->root() . '/m2-hotfixes/2026-02-10_249-2026-02-001-CE.patch');
        self::assertFileDoesNotExist($this->store->root() . '/m2-hotfixes/A-249-2026-02-001-CE.patch');
        self::assertFileDoesNotExist($this->store->root() . '/m2-hotfixes/B-249-2026-01-001-CE.patch');
    }

    public function test_it_fixes_a_duplicate_store_with_sync_prune(): void
    {
        $this->store = FixtureStore::create('duplicate', self::$server->baseUrl());

        $prune = $this->store->patchline(['sync', '--prune']);
        $states = $this->states($this->store->patchline(['status', '--format=json'])['stdout']);

        self::assertSame(0, $prune['exitCode'], $prune['stdout'] . $prune['stderr']);
        self::assertSame('queued', $states[FixtureStore::A]);
        self::assertFileDoesNotExist($this->store->root() . '/m2-hotfixes/copy-of-a.patch');
        self::assertFileExists($this->store->root() . '/vendor/magento/magento-cloud-patches/patches/a.patch');
    }

    public function test_it_downloads_an_entitled_patch_with_credentials_from_composer_auth(): void
    {
        $this->store = FixtureStore::create('entitled-on-open-source', self::$server->baseUrl());
        $target = $this->store->root() . '/m2-hotfixes/2026-03-10_249-2026-03-001-CE.patch';

        $withoutCredentials = $this->store->patchline(['sync', '--include-entitled']);
        $withCredentials = $this->store->patchline(
            ['sync', '--include-entitled'],
            ['COMPOSER_AUTH' => FixtureStore::COMPOSER_AUTH],
        );

        self::assertFileExists($target);
        self::assertSame(
            file_get_contents(__DIR__ . '/../Fixtures/diffs/249-2026-03-001-CE.diff'),
            file_get_contents($target),
        );
        self::assertSame(0, $withCredentials['exitCode'], $withCredentials['stdout'] . $withCredentials['stderr']);
        self::assertStringContainsString('needs repo.magento.com credentials', $withoutCredentials['stdout']);
        self::assertStringNotContainsString('e2e-secret', $withCredentials['stdout'] . $withCredentials['stderr']);
    }

    public function test_it_leaves_the_store_untouched_with_sync_dry_run(): void
    {
        $this->store = FixtureStore::create('nothing-patched', self::$server->baseUrl());
        $before = $this->store->snapshot();

        $result = $this->store->patchline(['sync', '--dry-run']);

        self::assertSame($before, $this->store->snapshot());
        self::assertStringContainsString('m2-hotfixes/2026-01-13_249-2026-01-001-CE.patch', $result['stdout']);
        self::assertSame(1, $result['exitCode'], $result['stderr']);
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
