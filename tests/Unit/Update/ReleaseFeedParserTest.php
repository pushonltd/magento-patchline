<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Update;

use Patchline\Update\ReleaseFeedParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseFeedParser::class)]
final class ReleaseFeedParserTest extends TestCase
{
    /**
     * @param list<mixed> $entries
     */
    private static function feed(array $entries): string
    {
        $packages = ['pushonltd/magento-patchline' => $entries];

        return json_encode(['minified' => 'composer/2.0', 'packages' => $packages], JSON_THROW_ON_ERROR);
    }

    public function test_it_returns_the_highest_stable_version_from_the_packagist_feed(): void
    {
        $json = self::feed([['version' => 'v0.0.2'], ['version' => 'v0.0.1'], ['version' => 'v0.0.10']]);

        self::assertSame('0.0.10', (new ReleaseFeedParser())->latestStable($json));
    }

    public function test_it_strips_the_leading_v_from_the_version(): void
    {
        self::assertSame('1.2.3', (new ReleaseFeedParser())->latestStable(self::feed([['version' => 'v1.2.3']])));
    }

    public function test_it_ignores_dev_alpha_beta_and_rc_versions(): void
    {
        $json = self::feed([
            ['version' => 'dev-main'],
            ['version' => 'v2.0.0-alpha1'],
            ['version' => 'v2.0.0-beta2'],
            ['version' => 'v2.0.0-RC1'],
            ['version' => 'v1.0.0'],
        ]);

        self::assertSame('1.0.0', (new ReleaseFeedParser())->latestStable($json));
    }

    public function test_it_ignores_entries_without_a_string_version(): void
    {
        $json = self::feed([['version' => 5], ['name' => 'x'], 'v9.9.9', null, ['version' => 'v1.0.0']]);

        self::assertSame('1.0.0', (new ReleaseFeedParser())->latestStable($json));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableFeeds(): array
    {
        return [
            'invalid json' => ['{nope'],
            'not an object' => ['"text"'],
            'missing package key' => ['{"packages":{"other/pkg":[{"version":"v1.0.0"}]}}'],
            'packages not an object' => ['{"packages":[]}'],
            'package entries not a list' => ['{"packages":{"pushonltd/magento-patchline":"x"}}'],
            'no stable versions' => [self::feed([['version' => 'dev-main'], ['version' => 'v1.0.0-beta1']])],
        ];
    }

    #[DataProvider('unusableFeeds')]
    public function test_it_returns_null_for_invalid_json_a_missing_package_key_or_no_stable_versions(
        string $json,
    ): void {
        self::assertNull((new ReleaseFeedParser())->latestStable($json));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidReleases(): array
    {
        return [
            'junk' => ['v1.x-junk'],
            'build metadata' => ['9.9.9+build'],
            'empty' => [''],
            'no version set' => ['1.0.0+no-version-set'],
            'patch suffix' => ['v9.0.0-p1'],
        ];
    }

    #[DataProvider('invalidReleases')]
    public function test_it_skips_feed_entries_whose_version_is_not_a_valid_release_without_throwing(
        string $version,
    ): void {
        $json = self::feed([['version' => $version], ['version' => 'v1.0.0']]);

        self::assertSame('1.0.0', (new ReleaseFeedParser())->latestStable($json));
    }

    public function test_it_picks_the_highest_version_regardless_of_feed_entry_order(): void
    {
        $versions = ['v0.3.0', 'v1.0.0', 'v0.10.0', 'v0.9.9'];

        foreach ([$versions, array_reverse($versions)] as $order) {
            $json = self::feed(array_map(static fn (string $v): array => ['version' => $v], $order));
            self::assertSame('1.0.0', (new ReleaseFeedParser())->latestStable($json));
        }
    }
}
