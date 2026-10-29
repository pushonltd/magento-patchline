<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Update;

use DateTimeImmutable;
use Patchline\Infrastructure\HttpResponse;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\PackageVersion;
use Patchline\Tests\Double\FakeHttpClient;
use Patchline\Tests\Double\FrozenClock;
use Patchline\Tests\Double\TempDirectory;
use Patchline\Update\ReleaseFeed;
use Patchline\Update\ReleaseFeedParser;
use Patchline\Update\UpdateAvailability;
use Patchline\Update\UpdateChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UpdateChecker::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(ReleaseFeed::class)]
#[UsesClass(ReleaseFeedParser::class)]
#[UsesClass(UpdateAvailability::class)]
final class UpdateCheckerTest extends TestCase
{
    private const URL = 'https://repo.packagist.org/p2/example/patchline.json';
    private const NOW = 1_800_000_000;

    private TempDirectory $dir;
    private FakeHttpClient $http;
    private UpdateChecker $checker;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
        $this->http = new FakeHttpClient();
        $clock = new FrozenClock((new DateTimeImmutable())->setTimestamp(self::NOW));
        $this->checker = new UpdateChecker(new LocalFilesystem(), $this->http, $clock);
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function test_it_returns_the_newer_version_from_the_feed(): void
    {
        $this->http->respond(self::URL, 200, $this->feed('0.0.3'));

        $result = $this->check('0.0.2');

        self::assertSame('0.0.3', $result);
    }

    public function test_it_makes_no_request_when_the_cache_is_younger_than_24_hours(): void
    {
        $this->writeCache(self::NOW - 86399, '0.0.3');

        $result = $this->check('0.0.2');

        self::assertSame('0.0.3', $result);
        self::assertSame([], $this->http->requests);
    }

    public function test_it_fetches_again_when_the_cache_is_older_than_24_hours(): void
    {
        $this->writeCache(self::NOW - 86400, '0.0.3');
        $this->http->respond(self::URL, 200, $this->feed('0.0.4'));

        $result = $this->check('0.0.2');

        self::assertSame('0.0.4', $result);
        self::assertCount(1, $this->http->requests);
    }

    public function test_it_replaces_the_cache_file_with_the_new_result_when_it_refreshes(): void
    {
        $this->writeCache(self::NOW - 90000, '0.0.3');
        $this->http->respond(self::URL, 200, $this->feed('0.0.4'));

        $this->check('0.0.2');

        self::assertSame(
            ['checked_at' => self::NOW, 'latest' => '0.0.4'],
            json_decode((string) file_get_contents($this->cacheFile()), true),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedCaches(): array
    {
        return [
            'invalid json' => ['{nope'],
            'not an object' => ['"text"'],
            'checked_at is a string' => ['{"checked_at": "yesterday", "latest": "0.0.3"}'],
            'latest is a number' => ['{"checked_at": 1799999000, "latest": 3}'],
            'latest is not a release' => ['{"checked_at": 1799999000, "latest": "dev-main"}'],
            'latest is missing' => ['{"checked_at": 1799999000}'],
            'checked_at in the future' => ['{"checked_at": 1800000600, "latest": "0.0.9"}'],
        ];
    }

    #[DataProvider('malformedCaches')]
    public function test_it_treats_a_malformed_or_future_dated_cache_file_as_no_cache(string $cache): void
    {
        file_put_contents($this->cacheFile(), $cache);
        $this->http->respond(self::URL, 200, $this->feed('0.0.4'));

        $result = $this->check('0.0.2');

        self::assertSame('0.0.4', $result);
        self::assertCount(1, $this->http->requests);
    }

    public function test_it_ignores_the_cache_when_caching_is_disabled(): void
    {
        $this->writeCache(self::NOW - 10, null);
        $this->http->respond(self::URL, 200, $this->feed('0.0.3'));

        $result = $this->check('0.0.2', useCache: false);

        self::assertSame('0.0.3', $result);
        self::assertCount(1, $this->http->requests);
    }

    public function test_it_returns_null_when_the_feed_cannot_be_reached_or_returns_a_non_200_status(): void
    {
        $this->http->fail(self::URL);
        $unreachable = $this->check('0.0.2', useCache: false);
        $this->http->respond(self::URL, 500, $this->feed('0.0.3'));
        $serverError = $this->check('0.0.2', useCache: false);

        self::assertNull($unreachable);
        self::assertNull($serverError);
    }

    public function test_it_caches_a_failed_check_so_the_next_run_makes_no_request(): void
    {
        $this->http->fail(self::URL);

        $this->check('0.0.2');
        $second = $this->check('0.0.2');

        self::assertNull($second);
        self::assertCount(1, $this->http->requests);
    }

    public function test_it_still_returns_the_result_when_the_cache_cannot_be_written(): void
    {
        file_put_contents($this->dir->path('blocker'), 'a file, not a directory');
        $this->http->respond(self::URL, 200, $this->feed('0.0.3'));

        $result = $this->checker->latestNewerThan('0.0.2', self::URL, $this->dir->path('blocker/cache'), true);

        self::assertSame('0.0.3', $result);
    }

    public function test_it_makes_no_request_when_the_current_version_is_not_a_release(): void
    {
        $this->http->respond(self::URL, 200, $this->feed('0.0.3'));

        $result = $this->check('dev-main');

        self::assertNull($result);
        self::assertSame([], $this->http->requests);
    }

    private function check(string $current, bool $useCache = true): ?string
    {
        return $this->checker->latestNewerThan($current, self::URL, $this->dir->path(), $useCache);
    }

    private function cacheFile(): string
    {
        return $this->dir->path('.patchline_update_check.json');
    }

    private function writeCache(int $checkedAt, ?string $latest): void
    {
        file_put_contents($this->cacheFile(), json_encode(['checked_at' => $checkedAt, 'latest' => $latest]));
    }

    private function feed(string $version): string
    {
        return (string) json_encode(['packages' => [PackageVersion::PACKAGE => [['version' => $version]]]]);
    }
}
