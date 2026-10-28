<?php

declare(strict_types=1);

namespace Patchline\Installation;

use JsonException;
use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\Filesystem;

final class InstallationReader
{
    private const CLOUD_PATCHES = 'magento/magento-cloud-patches';
    private const ENTERPRISE = 'magento/product-enterprise-edition';
    private const BASE_PACKAGES = [
        self::ENTERPRISE,
        'magento/product-community-edition',
        'magento/magento2-base',
    ];

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public function read(string $root): Installation
    {
        $packages = $this->readPackages($root);
        $baseVersion = $this->baseVersion($packages);
        if ($baseVersion === null) {
            throw new RuntimeFailure(
                'No Magento package found in ' . $root . '. Check that --root points at a Magento project'
                . ' with an up-to-date composer.lock.',
            );
        }

        $composerJson = $this->readComposerJson($root);

        return new Installation(
            $root,
            $packages,
            $this->replaced($composerJson),
            isset($packages[self::ENTERPRISE]) ? Edition::Commerce : Edition::OpenSource,
            $baseVersion,
            $packages[self::CLOUD_PATCHES] ?? null,
            $this->filesystem->isFile($root . '/vendor/magento/magento-cloud-patches/patches.json'),
            $this->settings($composerJson),
        );
    }

    /** @return array<string, string> */
    private function readPackages(string $root): array
    {
        $lock = $root . '/composer.lock';
        if ($this->filesystem->isFile($lock)) {
            $data = $this->decode($lock);

            return $this->collect($this->list($data['packages'] ?? []), $this->list($data['packages-dev'] ?? []));
        }

        $installed = $root . '/vendor/composer/installed.json';
        if (!$this->filesystem->isFile($installed)) {
            throw new RuntimeFailure(
                'Cannot find ' . $lock . ' or ' . $installed . '. Run composer install first.',
            );
        }

        $data = $this->decode($installed);

        return $this->collect($this->list(array_is_list($data) ? $data : ($data['packages'] ?? [])));
    }

    /**
     * @param list<mixed> ...$lists
     * @return array<string, string>
     */
    private function collect(array ...$lists): array
    {
        $packages = [];
        foreach ($lists as $list) {
            foreach ($list as $package) {
                $name = is_array($package) ? ($package['name'] ?? null) : null;
                $version = is_array($package) ? ($package['version'] ?? null) : null;
                if (!is_string($name) || !is_string($version)) {
                    continue;
                }
                $packages[$name] = ltrim($version, 'vV');
            }
        }

        return $packages;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /** @param array<string, string> $packages */
    private function baseVersion(array $packages): ?string
    {
        foreach (self::BASE_PACKAGES as $name) {
            if (isset($packages[$name])) {
                return $packages[$name];
            }
        }

        return null;
    }

    /** @return array<mixed> */
    private function readComposerJson(string $root): array
    {
        $path = $root . '/composer.json';

        return $this->filesystem->isFile($path) ? $this->decode($path) : [];
    }

    /**
     * @param array<mixed> $composerJson
     * @return list<string>
     */
    private function replaced(array $composerJson): array
    {
        $replace = $composerJson['replace'] ?? [];

        return is_array($replace) ? array_map('strval', array_keys($replace)) : [];
    }

    /**
     * @param array<mixed> $composerJson
     * @return array<string, scalar|list<string>>
     */
    private function settings(array $composerJson): array
    {
        $extra = $composerJson['extra'] ?? null;
        $patchline = is_array($extra) ? ($extra['patchline'] ?? null) : null;
        if (!is_array($patchline)) {
            return [];
        }

        $settings = [];
        foreach ($patchline as $key => $value) {
            if (is_scalar($value)) {
                $settings[(string) $key] = $value;
            } elseif (is_array($value) && array_is_list($value) && $this->allStrings($value)) {
                $settings[(string) $key] = $value;
            }
        }

        return $settings;
    }

    /**
     * @param list<mixed> $values
     * @phpstan-assert-if-true list<string> $values
     */
    private function allStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<mixed> */
    private function decode(string $path): array
    {
        try {
            $data = json_decode($this->filesystem->read($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeFailure('Cannot parse ' . $path . ': ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data)) {
            throw new RuntimeFailure('Cannot parse ' . $path . ': expected a JSON object or list.');
        }

        return $data;
    }
}
