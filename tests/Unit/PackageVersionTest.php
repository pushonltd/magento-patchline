<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit;

use Composer\InstalledVersions;
use Patchline\PackageVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PackageVersion::class)]
final class PackageVersionTest extends TestCase
{
    public function test_it_reports_the_version_composer_installed(): void
    {
        $expected = PackageVersion::format(
            (string) InstalledVersions::getPrettyVersion(PackageVersion::PACKAGE),
            InstalledVersions::getReference(PackageVersion::PACKAGE),
        );

        self::assertSame($expected, PackageVersion::current());
    }

    /**
     * @return array<string, array{string, string|null, string}>
     */
    public static function versions(): array
    {
        $commit = 'e8babafb150cc9f21c7db25786c582101849da43';

        return [
            'release tag' => ['v0.0.1', $commit, '0.0.1'],
            'release without a v' => ['0.0.1', $commit, '0.0.1'],
            'pre-release tag' => ['v1.0.0-RC1', null, '1.0.0-RC1'],
            'branch' => ['dev-main', $commit, 'dev-main@e8babaf'],
            'branch without a commit' => ['dev-main', null, 'dev-main'],
            'branch named like a version' => ['dev-v2', '', 'dev-v2'],
        ];
    }

    #[DataProvider('versions')]
    public function test_it_formats_the_installed_version(string $pretty, ?string $reference, string $expected): void
    {
        self::assertSame($expected, PackageVersion::format($pretty, $reference));
    }
}
