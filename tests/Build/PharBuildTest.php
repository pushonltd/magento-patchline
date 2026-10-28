<?php

declare(strict_types=1);

namespace Patchline\Tests\Build;

use Patchline\Tests\Double\TempDirectory;
use Patchline\Tests\Support\PhpProcess;
use Phar;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Builds the PHAR once (version "v9.9.9-RC1", so the stripped "v" is covered too) and inspects the result.
 */
#[CoversNothing]
final class PharBuildTest extends TestCase
{
    private static TempDirectory $output;

    public static function setUpBeforeClass(): void
    {
        self::$output = TempDirectory::create();
        $build = PhpProcess::run([
            dirname(__DIR__, 2) . '/build/build.php', '--version=v9.9.9-RC1', '--output=' . self::$output->path(),
        ]);
        self::assertSame(0, $build['exitCode'], $build['stdout'] . $build['stderr']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$output->remove();
    }

    public function test_it_builds_a_phar_that_runs_version_without_the_source_tree_or_vendor_present(): void
    {
        $isolated = TempDirectory::create();
        copy(self::$output->path('patchline.phar'), $isolated->path('patchline.phar'));

        $result = PhpProcess::run([$isolated->path('patchline.phar'), '--version'], $isolated->path());
        $isolated->remove();

        self::assertSame(0, $result['exitCode'], $result['stderr']);
        self::assertSame("patchline 9.9.9-RC1\n", $result['stdout']);
    }

    public function test_it_embeds_the_version_given_to_the_build_without_a_leading_v(): void
    {
        $result = PhpProcess::run([self::$output->path('patchline.phar'), '--version']);

        self::assertSame("patchline 9.9.9-RC1\n", $result['stdout']);
    }

    public function test_it_ships_only_runtime_dependencies_and_no_tests(): void
    {
        $paths = [];
        foreach (new RecursiveIteratorIterator(new Phar(self::$output->path('patchline.phar'))) as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $paths[] = (string) preg_replace('#^phar://.*?\.phar#', '', $file->getPathname());
        }

        self::assertContains('/vendor/symfony/console/Application.php', $paths);
        self::assertContains('/src/PackageVersion.php', $paths);
        self::assertSame([], array_values(array_filter(
            $paths,
            static fn (string $path): bool => preg_match('#^/(vendor/(phpunit|phpstan|squizlabs)/|tests/)#', $path)
                === 1,
        )));
    }

    public function test_it_writes_a_sha256_checksum_file_next_to_the_phar(): void
    {
        $checksum = (string) file_get_contents(self::$output->path('patchline.phar.sha256'));

        self::assertSame(
            hash_file('sha256', self::$output->path('patchline.phar')) . "  patchline.phar\n",
            $checksum,
        );
    }
}
