<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Queue\Source;

use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use Patchline\Queue\Source\CloudPatchesSource;
use Patchline\Queue\Source\SourceResult;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CloudPatchesSource::class)]
#[CoversClass(SourceResult::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(QueueEntry::class)]
final class CloudPatchesSourceTest extends TestCase
{
    private const DIR = 'vendor/magento/magento-cloud-patches';

    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function test_it_queues_a_cloud_patch_whose_composer_constraint_matches_the_installed_version(): void
    {
        $this->patchesJson([
            'magento/magento2-base' => [
                'Old fix' => ['2.4.0 - 2.4.5' => 'old.patch'],
                'Monthly' => ['2.4.8 - 2.4.9' => 'monthly.patch', '~2.3.0' => 'ancient.patch'],
            ],
        ]);
        $this->file(self::DIR . '/patches/old.patch', 'old');
        $this->file(self::DIR . '/patches/monthly.patch', 'monthly');
        $this->file(self::DIR . '/patches/ancient.patch', 'ancient');

        $result = $this->collect(['magento/magento2-base' => '2.4.8']);

        self::assertCount(1, $result->entries);
        self::assertSame(QueueOrigin::CloudRequired, $result->entries[0]->origin);
        self::assertSame('Monthly', $result->entries[0]->title);
        self::assertSame(self::DIR . '/patches/monthly.patch', $result->entries[0]->path);
        self::assertSame([], $result->warnings);
    }

    public function test_it_skips_cloud_patches_for_packages_that_are_not_installed(): void
    {
        $this->patchesJson(['vertex/module-tax' => ['Fix' => ['>=1.0' => 'fix.patch']]]);
        $this->file(self::DIR . '/patches/fix.patch', 'fix');

        $result = $this->collect(['magento/magento2-base' => '2.4.8']);

        self::assertSame([], $result->entries);
    }

    public function test_it_skips_a_listed_patch_file_that_does_not_exist(): void
    {
        $this->patchesJson(['magento/magento2-base' => ['Fix' => ['2.4.8' => 'missing.patch']]]);

        $result = $this->collect(['magento/magento2-base' => '2.4.8']);

        self::assertSame([], $result->entries);
    }

    public function test_it_records_the_sha256_of_every_queued_file(): void
    {
        $this->patchesJson(['magento/magento2-base' => ['Fix' => ['2.4.8' => 'fix.patch']]]);
        $this->file(self::DIR . '/patches/fix.patch', 'contents');

        $result = $this->collect(['magento/magento2-base' => '2.4.8']);

        self::assertSame(hash('sha256', 'contents'), $result->entries[0]->sha256);
    }

    public function test_it_returns_an_empty_cloud_source_when_magento_cloud_patches_is_not_installed(): void
    {
        $result = $this->collect(['magento/magento2-base' => '2.4.8']);

        self::assertSame([], $result->entries);
        self::assertSame([], $result->warnings);
    }

    public function test_it_warns_about_an_unparseable_constraint_and_skips_that_entry(): void
    {
        $this->patchesJson(['magento/magento2-base' => [
            'Broken' => ['not a constraint!' => 'broken.patch'],
            'Good' => ['2.4.8' => 'good.patch'],
        ]]);
        $this->file(self::DIR . '/patches/broken.patch', 'b');
        $this->file(self::DIR . '/patches/good.patch', 'g');

        $result = $this->collect(['magento/magento2-base' => '2.4.8']);

        self::assertSame(['Good'], array_map(static fn ($e) => $e->title, $result->entries));
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('not a constraint!', $result->warnings[0]);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function releaseOrdering(): array
    {
        return [
            'patch release after base' => ['>2.4.8', '2.4.8-p3', true],
            'base is not after itself' => ['>2.4.8', '2.4.8', false],
            'exact base excludes patch' => ['2.4.8', '2.4.8-p1', false],
            'p10 after p9' => ['>2.4.4-p9', '2.4.4-p10', true],
            'p9 before p10' => ['<2.4.4-p10', '2.4.4-p9', true],
            'range upper bound excludes p10' => ['>=2.4.4 <2.4.4-p10', '2.4.4-p10', false],
            'hyphen range inside' => ['2.1.4 - 2.1.7', '2.1.5', true],
            'hyphen range above' => ['2.1.4 - 2.1.7', '2.1.8', false],
            'tilde' => ['~2.1.4', '2.1.99', true],
            'tilde next minor' => ['~2.1.4', '2.2.0', false],
            'or second branch' => ['2.1.10 - 2.1.14 || 2.2.2 - 2.2.5', '2.2.3', true],
            'space and' => ['>=2.4.0 <2.4.4', '2.4.3-p2', true],
        ];
    }

    #[DataProvider('releaseOrdering')]
    public function test_it_orders_magento_patch_releases_after_their_base_release_in_constraints(
        string $constraint,
        string $installed,
        bool $queued,
    ): void {
        $this->patchesJson(['magento/magento2-base' => ['Fix' => [$constraint => 'fix.patch']]]);
        $this->file(self::DIR . '/patches/fix.patch', 'fix');

        $result = $this->collect(['magento/magento2-base' => $installed]);

        self::assertCount($queued ? 1 : 0, $result->entries);
        self::assertSame([], $result->warnings);
    }

    public function test_it_accepts_every_constraint_string_found_in_magento_cloud_patches_1_1_18_patches_json(): void
    {
        $lines = file(
            __DIR__ . '/../../../Fixtures/constraints/cloud-patches-1.1.18.txt',
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
        );
        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
        $files = [];
        foreach ($lines as $i => $line) {
            $files[$line] = 'p' . $i . '.patch';
        }
        $this->patchesJson(['magento/magento2-base' => ['Fix' => $files]]);

        $result = $this->collect(['magento/magento2-base' => '2.4.6-p3']);

        self::assertSame([], $result->warnings);
    }

    public function test_it_no_longer_ships_its_own_constraint_parser(): void
    {
        self::assertFalse(class_exists('Patchline\\Version\\ConstraintParser'));
        self::assertDirectoryDoesNotExist(__DIR__ . '/../../../../src/Version');
    }

    public function test_it_warns_and_returns_nothing_when_patches_json_has_an_unexpected_shape(): void
    {
        $this->file(self::DIR . '/patches.json', '{"magento/magento2-base": {"Fix": "fix.patch"}}');

        $result = $this->collect(['magento/magento2-base' => '2.4.8']);

        self::assertSame([], $result->entries);
        self::assertCount(1, $result->warnings);
    }

    public function test_it_warns_and_returns_nothing_when_patches_json_is_not_json(): void
    {
        $this->file(self::DIR . '/patches.json', '{nope');

        $result = $this->collect([]);

        self::assertSame([], $result->entries);
        self::assertCount(1, $result->warnings);
    }

    #[DataProvider('malformedPatchesJson')]
    public function test_it_warns_about_every_level_of_a_malformed_patches_json(string $json): void
    {
        $this->file(self::DIR . '/patches.json', $json);

        $result = $this->collect(['magento/magento2-base' => '2.4.8']);

        self::assertSame([], $result->entries);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('is not in the expected format', $result->warnings[0]);
    }

    /** @return array<string, array{string}> */
    public static function malformedPatchesJson(): array
    {
        return [
            'top level is a scalar' => ['"patches"'],
            'package maps to a string' => ['{"magento/magento2-base": "fix.patch"}'],
            'title maps to a string' => ['{"magento/magento2-base": {"Fix": "fix.patch"}}'],
            'constraint maps to a list' => ['{"magento/magento2-base": {"Fix": {"~2.4.0": ["fix.patch"]}}}'],
        ];
    }

    /** @param array<string, string> $packages */
    private function collect(array $packages): SourceResult
    {
        return (new CloudPatchesSource(new LocalFilesystem()))
            ->collect($this->dir->path(), $packages);
    }

    /** @param array<string, mixed> $data */
    private function patchesJson(array $data): void
    {
        $this->file(self::DIR . '/patches.json', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function file(string $relative, string $contents): void
    {
        $path = $this->dir->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }
}
