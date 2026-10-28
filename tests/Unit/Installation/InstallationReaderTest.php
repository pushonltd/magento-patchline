<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Installation;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Installation\Edition;
use Patchline\Installation\Installation;
use Patchline\Installation\InstallationReader;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstallationReader::class)]
#[CoversClass(Installation::class)]
#[CoversClass(Edition::class)]
#[UsesClass(LocalFilesystem::class)]
final class InstallationReaderTest extends TestCase
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

    public function test_it_reads_installed_packages_and_versions_from_composer_lock_including_dev_packages(): void
    {
        $this->write('composer.lock', json_encode([
            'packages' => [['name' => 'magento/magento2-base', 'version' => '2.4.8']],
            'packages-dev' => [['name' => 'phpunit/phpunit', 'version' => '10.5.0']],
        ], JSON_THROW_ON_ERROR));

        $installation = $this->read();

        self::assertSame(
            ['magento/magento2-base' => '2.4.8', 'phpunit/phpunit' => '10.5.0'],
            $installation->packages,
        );
        self::assertSame($this->dir->path(), $installation->root);
    }

    public function test_it_falls_back_to_vendor_composer_installed_json_when_there_is_no_lock_file(): void
    {
        $this->write('vendor/composer/installed.json', json_encode([
            'packages' => [['name' => 'magento/magento2-base', 'version' => '2.4.7']],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['magento/magento2-base' => '2.4.7'], $this->read()->packages);
    }

    public function test_it_falls_back_to_the_composer_1_installed_json_shape(): void
    {
        $this->write('vendor/composer/installed.json', json_encode([
            ['name' => 'magento/magento2-base', 'version' => '2.3.7'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['magento/magento2-base' => '2.3.7'], $this->read()->packages);
    }

    public function test_it_strips_a_leading_v_from_package_versions(): void
    {
        $this->lock([['name' => 'magento/magento2-base', 'version' => 'v2.4.8-p1']]);

        self::assertSame('2.4.8-p1', $this->read()->packages['magento/magento2-base']);
    }

    public function test_it_detects_the_base_version_from_the_enterprise_edition_before_the_community_edition(): void
    {
        $this->lock([
            ['name' => 'magento/magento2-base', 'version' => '2.4.1'],
            ['name' => 'magento/product-community-edition', 'version' => '2.4.2'],
            ['name' => 'magento/product-enterprise-edition', 'version' => '2.4.3'],
        ]);

        $installation = $this->read();

        self::assertSame('2.4.3', $installation->baseVersion);
        self::assertSame(Edition::Commerce, $installation->edition);
    }

    public function test_it_detects_open_source_from_the_community_edition(): void
    {
        $this->lock([
            ['name' => 'magento/magento2-base', 'version' => '2.4.1'],
            ['name' => 'magento/product-community-edition', 'version' => '2.4.2'],
        ]);

        $installation = $this->read();

        self::assertSame('2.4.2', $installation->baseVersion);
        self::assertSame(Edition::OpenSource, $installation->edition);
    }

    public function test_it_falls_back_to_magento2_base_for_the_base_version(): void
    {
        $this->lock([['name' => 'magento/magento2-base', 'version' => '2.4.1']]);

        self::assertSame('2.4.1', $this->read()->baseVersion);
    }

    public function test_it_lists_packages_replaced_in_composer_json(): void
    {
        $this->lock([['name' => 'magento/magento2-base', 'version' => '2.4.8']]);
        $this->write('composer.json', json_encode([
            'replace' => ['magento/module-a' => '*', 'magento/module-b' => '2.4.8'],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['magento/module-a', 'magento/module-b'], $this->read()->replacedPackages);
    }

    public function test_it_exposes_extra_patchline_settings_from_composer_json(): void
    {
        $this->lock([['name' => 'magento/magento2-base', 'version' => '2.4.8']]);
        $this->write('composer.json', json_encode([
            'extra' => ['patchline' => ['registry' => 'https://x', 'skip' => ['a', 'b'], 'flag' => true]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(
            ['registry' => 'https://x', 'skip' => ['a', 'b'], 'flag' => true],
            $this->read()->settings,
        );
    }

    public function test_it_reports_whether_magento_cloud_patches_is_locked_and_installed_on_disk(): void
    {
        $this->lock([
            ['name' => 'magento/magento2-base', 'version' => '2.4.8'],
            ['name' => 'magento/magento-cloud-patches', 'version' => '1.1.18'],
        ]);
        $this->write('vendor/magento/magento-cloud-patches/patches.json', '{}');

        $installation = $this->read();

        self::assertSame('1.1.18', $installation->cloudPatchesVersion);
        self::assertTrue($installation->cloudPatchesOnDisk);
    }

    public function test_it_reports_cloud_patches_as_absent_when_not_locked_or_on_disk(): void
    {
        $this->lock([['name' => 'magento/magento2-base', 'version' => '2.4.8']]);

        $installation = $this->read();

        self::assertNull($installation->cloudPatchesVersion);
        self::assertFalse($installation->cloudPatchesOnDisk);
    }

    public function test_it_fails_with_a_runtime_failure_when_no_magento_package_is_installed(): void
    {
        $this->lock([['name' => 'vendor/other', 'version' => '1.0.0']]);

        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage(
            'No Magento package found in ' . $this->dir->path() . '. Check that --root points at a Magento project'
            . ' with an up-to-date composer.lock.',
        );

        $this->read();
    }

    public function test_it_fails_with_a_runtime_failure_naming_the_file_for_invalid_json(): void
    {
        $this->write('composer.lock', '{not json');

        try {
            $this->read();
            self::fail('Expected RuntimeFailure');
        } catch (RuntimeFailure $e) {
            self::assertSame('Cannot parse ' . $this->dir->path('composer.lock') . ': Syntax error', $e->getMessage());
            self::assertSame(0, $e->getCode());
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function test_it_fails_with_a_runtime_failure_for_json_that_is_not_an_object_or_a_list(): void
    {
        $this->write('composer.lock', '"just a string"');

        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage(
            'Cannot parse ' . $this->dir->path('composer.lock') . ': expected a JSON object or list.',
        );

        $this->read();
    }

    public function test_it_reads_json_nested_511_levels_deep_but_not_512(): void
    {
        $this->write('composer.lock', str_repeat('[', 511) . str_repeat(']', 511));
        try {
            $this->read();
            self::fail('Expected RuntimeFailure');
        } catch (RuntimeFailure $e) {
            self::assertStringStartsWith('No Magento package found', $e->getMessage());
        }

        $this->write('composer.lock', str_repeat('[', 512) . str_repeat(']', 512));
        try {
            $this->read();
            self::fail('Expected RuntimeFailure');
        } catch (RuntimeFailure $e) {
            self::assertStringStartsWith('Cannot parse ', $e->getMessage());
        }
    }

    public function test_it_skips_package_entries_without_a_name_or_a_version(): void
    {
        $this->write('composer.lock', json_encode(['packages' => [
            ['name' => 'only/name'],
            ['version' => '1.0.0'],
            'not an entry',
            ['name' => 'magento/magento2-base', 'version' => '2.4.8'],
        ]], JSON_THROW_ON_ERROR));

        self::assertSame(['magento/magento2-base' => '2.4.8'], $this->read()->packages);
    }

    public function test_it_reads_packages_given_as_a_json_object_keyed_by_name(): void
    {
        $this->write('composer.lock', '{"packages": {"x": {"name": "magento/magento2-base", "version": "2.4.8"}}}');

        self::assertSame(['magento/magento2-base' => '2.4.8'], $this->read()->packages);
    }

    public function test_it_keeps_only_scalar_and_string_list_settings(): void
    {
        $this->lock([['name' => 'magento/magento2-base', 'version' => '2.4.8']]);
        $this->write('composer.json', json_encode([
            'extra' => ['patchline' => [
                'ok-list' => ['a', 'b'],
                'mixed-list' => ['a', 1],
                'object' => ['k' => 'v'],
                'nothing' => null,
                'number' => 3,
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(['ok-list' => ['a', 'b'], 'number' => 3], $this->read()->settings);
    }

    public function test_it_ignores_extra_patchline_that_is_not_an_object(): void
    {
        $this->lock([['name' => 'magento/magento2-base', 'version' => '2.4.8']]);
        $this->write('composer.json', '{"extra": {"patchline": "nope"}}');

        self::assertSame([], $this->read()->settings);
    }

    public function test_it_lists_replaced_package_names_as_strings_even_when_they_look_like_numbers(): void
    {
        $this->lock([['name' => 'magento/magento2-base', 'version' => '2.4.8']]);
        $this->write('composer.json', '{"replace": {"123": "*"}}');

        self::assertSame(['123'], $this->read()->replacedPackages);
    }

    public function test_it_fails_with_a_runtime_failure_when_there_is_no_lock_or_installed_json(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage(
            'Cannot find ' . $this->dir->path('composer.lock') . ' or '
            . $this->dir->path('vendor/composer/installed.json') . '. Run composer install first.',
        );

        $this->read();
    }

    /** @param list<array{name: string, version: string}> $packages */
    private function lock(array $packages): void
    {
        $this->write('composer.lock', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->dir->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function read(): Installation
    {
        return (new InstallationReader(new LocalFilesystem()))->read($this->dir->path());
    }
}
