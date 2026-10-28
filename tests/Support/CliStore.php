<?php

declare(strict_types=1);

namespace Patchline\Tests\Support;

use Patchline\Tests\Double\TempDirectory;

/**
 * A tiny fake Magento store plus a local registry for CLI tests, built in a temp directory.
 *
 * One CE patch (`249-2026-01-001-CE`) changes `vendor/magento/module-a/a.php` from "one" to "two".
 * The diff is pre-filled in the diff cache, so no test needs the network.
 */
final class CliStore
{
    public const PATCH_ID = '249-2026-01-001-CE';
    public const FILE_NAME = '249-2026-01-001-CE.diff';
    public const TARGET = 'vendor/magento/module-a/a.php';

    private function __construct(private readonly TempDirectory $directory)
    {
    }

    public static function create(bool $patched): self
    {
        $store = new self(TempDirectory::create());
        $diff = self::diff();
        $store->write('composer.json', '{"name": "acme/store"}');
        $store->write('composer.lock', json_encode([
            'packages' => [['name' => 'magento/product-community-edition', 'version' => '2.4.9']],
        ], JSON_THROW_ON_ERROR));
        $store->write(self::TARGET, $patched ? "two\n" : "one\n");
        $store->write('var/patch_metadata/.patch_diffs/' . self::FILE_NAME, $diff);
        $store->write('registry.json', json_encode([
            '_schema' => ['version' => '1.0', 'patch_base_url' => 'https://repo.magento.com/patch'],
            '_areas' => ['CE' => ['description' => 'Community', 'detection' => 'base_version']],
            'patches' => [self::PATCH_ID => [
                'applies_to' => ['2.4.9'],
                'area' => 'CE',
                'requires' => [],
                'cves' => ['CVE-2026-0001'],
                'released' => '2026-01-13',
                'file_name' => self::FILE_NAME,
                'sha256' => hash('sha256', $diff),
            ]],
        ], JSON_THROW_ON_ERROR));

        return $store;
    }

    public function root(): string
    {
        return $this->directory->path();
    }

    public function registry(): string
    {
        return $this->directory->path('registry.json');
    }

    public function path(string $relative): string
    {
        return $this->directory->path($relative);
    }

    public function remove(): void
    {
        $this->directory->remove();
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->directory->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    private static function diff(): string
    {
        return "diff --git a/" . self::TARGET . " b/" . self::TARGET . "\n"
            . "--- a/" . self::TARGET . "\n+++ b/" . self::TARGET . "\n@@ -1,1 +1,1 @@\n-one\n+two\n";
    }
}
