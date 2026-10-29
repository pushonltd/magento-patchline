<?php

declare(strict_types=1);

namespace Patchline\Update;

use Composer\Semver\VersionParser;

/**
 * What counts as a release: a plain `1.2.3` (or `1.2.3.4`) version, with an optional leading `v`.
 */
final class ReleaseFeed
{
    /**
     * Returns the version without the `v`, or null when it is not a release.
     */
    public static function releaseVersion(string $version): ?string
    {
        $plain = (string) preg_replace('/^v(?=\d)/', '', $version);
        if (preg_match('/^\d+\.\d+\.\d+(\.\d+)?$/', $plain) !== 1) {
            return null;
        }

        return $plain;
    }

    public static function normalize(string $release): string
    {
        return (new VersionParser())->normalize($release);
    }
}
