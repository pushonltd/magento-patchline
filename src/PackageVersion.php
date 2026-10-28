<?php

declare(strict_types=1);

namespace Patchline;

use Composer\InstalledVersions;

/**
 * The version Composer installed: the release tag, or the branch plus its commit for a development install. The PHAR
 * build writes the release version into the Composer metadata it bundles, so the PHAR reports it the same way.
 */
final class PackageVersion
{
    public const PACKAGE = 'pushonltd/magento-patchline';

    public static function current(): string
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE)) {
            return 'unknown';
        }

        return self::format(
            (string) InstalledVersions::getPrettyVersion(self::PACKAGE),
            InstalledVersions::getReference(self::PACKAGE),
        );
    }

    /**
     * `v0.0.1` becomes `0.0.1`; a branch such as `dev-main` gets the short commit appended.
     */
    public static function format(string $prettyVersion, ?string $reference): string
    {
        if (str_starts_with($prettyVersion, 'dev-') && $reference !== null && $reference !== '') {
            return $prettyVersion . '@' . substr($reference, 0, 7);
        }

        return (string) preg_replace('/^v(?=\d)/', '', $prettyVersion);
    }
}
