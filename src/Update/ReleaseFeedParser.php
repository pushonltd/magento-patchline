<?php

declare(strict_types=1);

namespace Patchline\Update;

use Composer\Semver\Comparator;
use Patchline\PackageVersion;

/**
 * Reads Packagist's p2 metadata for this package and picks the highest stable release.
 */
final class ReleaseFeedParser
{
    public function latestStable(string $json): ?string
    {
        $latest = null;
        foreach ($this->versions($json) as $version) {
            $release = ReleaseFeed::releaseVersion($version);
            if ($release === null) {
                continue;
            }
            if ($latest !== null && !self::isGreater($release, $latest)) {
                continue;
            }

            $latest = $release;
        }

        return $latest;
    }

    private static function isGreater(string $release, string $other): bool
    {
        return Comparator::greaterThan(ReleaseFeed::normalize($release), ReleaseFeed::normalize($other));
    }

    /**
     * @return list<string>
     */
    private function versions(string $json): array
    {
        $data = json_decode($json, true);
        $packages = is_array($data) ? ($data['packages'] ?? null) : null;
        $entries = is_array($packages) ? ($packages[PackageVersion::PACKAGE] ?? null) : null;
        if (!is_array($entries)) {
            return [];
        }

        $versions = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['version']) || !is_string($entry['version'])) {
                continue;
            }

            $versions[] = $entry['version'];
        }

        return $versions;
    }
}
