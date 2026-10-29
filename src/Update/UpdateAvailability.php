<?php

declare(strict_types=1);

namespace Patchline\Update;

use Composer\Semver\Comparator;

/**
 * Decides whether the latest release is newer than the running version. Never throws.
 */
final class UpdateAvailability
{
    /**
     * @return string|null the latest version when it is newer than a released current version, otherwise null
     */
    public static function newerThan(string $current, ?string $latest): ?string
    {
        $currentRelease = ReleaseFeed::releaseVersion($current);
        $latestRelease = $latest === null ? null : ReleaseFeed::releaseVersion($latest);
        if ($currentRelease === null || $latestRelease === null) {
            return null;
        }

        if (!Comparator::greaterThan(ReleaseFeed::normalize($latestRelease), ReleaseFeed::normalize($currentRelease))) {
            return null;
        }

        return $latestRelease;
    }
}
