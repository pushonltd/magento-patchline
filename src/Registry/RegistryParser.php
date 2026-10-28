<?php

declare(strict_types=1);

namespace Patchline\Registry;

use Patchline\Exception\RuntimeFailure;

/**
 * Validates the decoded registry JSON into typed objects. Invalid areas and patches are
 * skipped with a warning; an unusable document or schema is a RuntimeFailure.
 */
final class RegistryParser
{
    private const DEFAULT_BASE_URL = 'https://repo.magento.com/patch';

    public function __construct(private readonly bool $allowInsecureLocalhost = false)
    {
    }

    public function parse(mixed $data): ParseResult
    {
        if (!is_array($data)) {
            throw new RuntimeFailure('The patch registry is not a JSON object. Check the --registry source.');
        }
        $schema = $data['_schema'] ?? null;
        $schema = is_array($schema) ? $schema : [];

        $version = $schema['version'] ?? null;
        if (!is_string($version) || preg_match('/^1(\.|$)/', $version) !== 1) {
            throw new RuntimeFailure(sprintf(
                'registry schema %s is not supported, update patchline',
                is_string($version) ? $version : '(missing)',
            ));
        }

        $warnings = [];
        $areas = $this->parseAreas($data['_areas'] ?? null, $warnings);
        $patches = $this->parsePatches($data['patches'] ?? null, $areas, $warnings);

        return new ParseResult(
            new Registry($version, $this->baseUrl($schema['patch_base_url'] ?? null), $areas, $patches),
            $warnings,
        );
    }

    private function baseUrl(mixed $url): string
    {
        if ($url === null) {
            return self::DEFAULT_BASE_URL;
        }
        if (!is_string($url)) {
            throw new RuntimeFailure('The registry patch_base_url is not a string.');
        }
        $url = rtrim($url, '/');
        if (str_starts_with($url, 'https://')) {
            return $url;
        }
        if ($this->allowInsecureLocalhost && preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)~', $url) === 1) {
            return $url;
        }

        throw new RuntimeFailure(sprintf('The registry patch_base_url "%s" must use https.', $url));
    }

    /**
     * @param list<string> $warnings
     *
     * @return array<string, Area>
     */
    private function parseAreas(mixed $raw, array &$warnings): array
    {
        $areas = [];
        foreach (is_array($raw) ? $raw : [] as $key => $entry) {
            $key = (string) $key;
            if (!is_array($entry)) {
                $warnings[] = sprintf('Skipped area %s: not an object.', $key);
                continue;
            }
            $package = $entry['composer_package'] ?? null;
            if (($entry['detection'] ?? null) !== 'base_version' && !is_string($package)) {
                $warnings[] = sprintf('Skipped area %s: neither detection nor composer_package is usable.', $key);
                continue;
            }
            $description = $entry['description'] ?? '';
            $areas[$key] = new Area(
                $key,
                is_string($description) ? $description : '',
                is_string($package) ? $package : null,
            );
        }

        return $areas;
    }

    /**
     * @param array<string, Area> $areas
     * @param list<string> $warnings
     *
     * @return array<string, RegistryPatch>
     */
    private function parsePatches(mixed $raw, array $areas, array &$warnings): array
    {
        $patches = [];
        foreach (is_array($raw) ? $raw : [] as $id => $entry) {
            $id = (string) $id;
            try {
                $patches[$id] = $this->parsePatch($id, $entry, $areas);
            } catch (\InvalidArgumentException $e) {
                $warnings[] = sprintf('Skipped patch %s: %s', $id, $e->getMessage());
            }
        }

        return $patches;
    }

    /**
     * @param array<string, Area> $areas
     */
    private function parsePatch(string $id, mixed $entry, array $areas): RegistryPatch
    {
        if (!is_array($entry)) {
            throw new \InvalidArgumentException('not an object.');
        }
        $area = $entry['area'] ?? null;
        if (!is_string($area) || !isset($areas[$area])) {
            throw new \InvalidArgumentException(sprintf('area "%s" is not defined.', is_string($area) ? $area : ''));
        }
        $fileName = $entry['file_name'] ?? null;
        if (!is_string($fileName) || preg_match('/^[A-Za-z0-9._-]+\.diff$/', $fileName) !== 1) {
            throw new \InvalidArgumentException('file_name is missing or not a plain .diff file name.');
        }
        $sha256 = $entry['sha256'] ?? null;
        if (!is_string($sha256) || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new \InvalidArgumentException('sha256 is missing or not 64 lowercase hex characters.');
        }

        return new RegistryPatch(
            $id,
            $this->stringList($entry, 'applies_to', true),
            $area,
            $this->stringList($entry, 'requires', true),
            $this->stringList($entry, 'cves', true),
            $this->date($entry['released'] ?? null),
            $fileName,
            $sha256,
            $this->stringList($entry, 'entitlements', false),
        );
    }

    /**
     * @param array<mixed> $entry
     *
     * @return list<string>
     */
    private function stringList(array $entry, string $key, bool $required): array
    {
        if (!array_key_exists($key, $entry)) {
            return $required ? throw new \InvalidArgumentException(sprintf('%s is missing.', $key)) : [];
        }
        $list = [];
        if (is_array($entry[$key])) {
            foreach ($entry[$key] as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException(sprintf('%s must be a list of strings.', $key));
                }
                $list[] = $value;
            }

            return $list;
        }

        throw new \InvalidArgumentException(sprintf('%s must be a list of strings.', $key));
    }

    private function date(mixed $value): \DateTimeImmutable
    {
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException('released is missing or not a YYYY-MM-DD date.');
        }

        return $date;
    }
}
