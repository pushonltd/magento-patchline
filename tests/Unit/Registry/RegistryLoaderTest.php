<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Registry;

use DateTimeImmutable;
use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\HttpResponse;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Registry\Area;
use Patchline\Registry\LoadedRegistry;
use Patchline\Registry\ParseResult;
use Patchline\Registry\Registry;
use Patchline\Registry\RegistryLoader;
use Patchline\Registry\RegistryParser;
use Patchline\Registry\RegistryPatch;
use Patchline\Registry\RegistrySource;
use Patchline\Tests\Double\FakeHttpClient;
use Patchline\Tests\Double\FrozenClock;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistryLoader::class)]
#[CoversClass(LoadedRegistry::class)]
#[CoversClass(RegistrySource::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(Area::class)]
#[UsesClass(ParseResult::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryParser::class)]
#[UsesClass(RegistryPatch::class)]
final class RegistryLoaderTest extends TestCase
{
    private const URL = 'https://repo.magento.com/patch/patch-registry.json';
    private const NOW = '2026-10-28 12:00:00';

    private TempDirectory $temp;
    private FakeHttpClient $http;
    private string $body;

    protected function setUp(): void
    {
        $this->temp = TempDirectory::create();
        $this->http = new FakeHttpClient();
        $body = file_get_contents(__DIR__ . '/../../Fixtures/registry/adobe-sample.json');
        self::assertIsString($body);
        $this->body = $body;
    }

    protected function tearDown(): void
    {
        $this->temp->remove();
    }

    public function test_it_uses_a_cache_younger_than_one_hour_without_a_network_request(): void
    {
        $this->writeCache($this->temp->path('var/patch_metadata'), 600);

        $loaded = $this->loader()->load($this->temp->path());

        self::assertSame(RegistrySource::Cache, $loaded->source);
        self::assertSame([], $this->http->requests);
        self::assertCount(17, $loaded->registry->patches);
    }

    public function test_it_fetches_the_remote_registry_and_writes_the_shared_cache_when_the_cache_is_expired(): void
    {
        $dir = $this->temp->path('var/patch_metadata');
        $this->writeCache($dir, 7200, '{}');
        $this->http->respond(self::URL, 200, $this->body);

        $loaded = $this->loader()->load($this->temp->path());

        self::assertSame(RegistrySource::Remote, $loaded->source);
        self::assertSame($this->body, file_get_contents($dir . '/.patch_registry_cache.json'));
    }

    public function test_it_falls_back_to_a_stale_cache_with_a_warning_stating_its_age_when_the_remote_fails(): void
    {
        $this->writeCache($this->temp->path('var/patch_metadata'), 3 * 3600 + 60);
        $this->http->fail(self::URL);

        $loaded = $this->loader()->load($this->temp->path());

        self::assertSame(RegistrySource::StaleCache, $loaded->source);
        self::assertCount(2, $loaded->warnings);
        self::assertStringContainsString('3 hours old', implode("\n", $loaded->warnings));
    }

    public function test_it_fails_when_the_remote_is_unreachable_and_no_cache_is_allowed(): void
    {
        $this->writeCache($this->temp->path('var/patch_metadata'), 7200);
        $this->http->fail(self::URL);

        $this->expectException(RuntimeFailure::class);

        $this->loader()->load($this->temp->path(), noCache: true);
    }

    public function test_it_loads_an_explicit_local_registry_file_without_touching_the_cache(): void
    {
        $file = $this->temp->path('registry.json');
        file_put_contents($file, $this->body);

        $loaded = $this->loader()->load($this->temp->path(), registry: $file);

        self::assertSame(RegistrySource::File, $loaded->source);
        self::assertSame([], $this->http->requests);
        self::assertFalse(is_dir($this->temp->path('var')));
    }

    public function test_it_treats_a_non_200_remote_response_as_unreachable(): void
    {
        $this->http->respond(self::URL, 500, $this->body);

        $this->expectException(RuntimeFailure::class);

        $this->loader()->load($this->temp->path());
    }

    public function test_it_stores_the_cache_under_a_custom_cache_directory_when_one_is_given(): void
    {
        $this->http->respond(self::URL, 200, $this->body);
        $custom = $this->temp->path('elsewhere');

        $this->loader()->load($this->temp->path(), cacheDir: $custom);

        self::assertFileExists($custom . '/.patch_registry_cache.json');
        self::assertFileDoesNotExist($this->temp->path('var/patch_metadata/.patch_registry_cache.json'));
    }

    public function test_it_replaces_an_expired_cache_file_with_the_fresh_remote_registry(): void
    {
        $dir = $this->temp->path('var/patch_metadata');
        $this->writeCache($dir, 7200, '{"old":true}');
        $this->http->respond(self::URL, 200, $this->body);

        $this->loader()->load($this->temp->path());
        $second = $this->loader()->load($this->temp->path());

        self::assertSame(RegistrySource::Cache, $second->source);
        self::assertCount(1, $this->http->requests);
    }

    public function test_it_never_writes_the_shared_cache_for_an_explicit_registry_url(): void
    {
        $this->http->respond('https://example.com/r.json', 200, $this->body);

        $loaded = $this->loader()->load($this->temp->path(), registry: 'https://example.com/r.json');

        self::assertSame(RegistrySource::Remote, $loaded->source);
        self::assertFalse(is_dir($this->temp->path('var')));
    }

    public function test_it_warns_instead_of_failing_when_the_cache_directory_is_not_writable(): void
    {
        $this->http->respond(self::URL, 200, $this->body);
        $blocker = $this->temp->path('blocker');
        file_put_contents($blocker, 'x');

        $loaded = $this->loader()->load($this->temp->path(), cacheDir: $blocker . '/sub');

        self::assertSame(RegistrySource::Remote, $loaded->source);
        self::assertCount(1, $loaded->warnings);
        self::assertStringContainsString('cache', $loaded->warnings[0]);
    }

    public function test_it_fails_with_the_reason_when_an_explicit_registry_url_cannot_be_fetched(): void
    {
        $this->http->fail('https://example.com/r.json');

        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('Could not fetch the registry: Cannot reach https://example.com/r.json.');

        $this->loader()->load($this->temp->path(), registry: 'https://example.com/r.json');
    }

    public function test_it_fails_when_an_explicit_registry_file_does_not_exist(): void
    {
        $missing = $this->temp->path('missing.json');

        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('The registry file ' . $missing . ' does not exist.');

        $this->loader()->load($this->temp->path(), registry: $missing);
    }

    public function test_it_rejects_a_plain_http_registry_url(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('The registry URL "http://example.com/r.json" must use https.');

        $this->loader()->load($this->temp->path(), registry: 'http://example.com/r.json');
    }

    public function test_it_rejects_plain_http_on_localhost_unless_insecure_localhost_is_allowed(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('must use https');

        $this->loader()->load($this->temp->path(), registry: 'http://127.0.0.1:8080/r.json');
    }

    public function test_it_accepts_plain_http_on_localhost_when_insecure_localhost_is_allowed(): void
    {
        $this->http->respond('http://127.0.0.1:8080/r.json', 200, $this->body);

        $loaded = $this->loader(allowInsecureLocalhost: true)
            ->load($this->temp->path(), registry: 'http://127.0.0.1:8080/r.json');

        self::assertSame(RegistrySource::Remote, $loaded->source);
    }

    public function test_it_rejects_plain_http_on_a_remote_host_even_when_insecure_localhost_is_allowed(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage('must use https');

        $this->loader(allowInsecureLocalhost: true)->load($this->temp->path(), registry: 'http://example.com/r.json');
    }

    public function test_it_treats_a_remote_response_that_is_not_json_as_unreachable_and_says_so(): void
    {
        $this->http->respond(self::URL, 200, '<html>maintenance</html>');

        $this->expectException(RuntimeFailure::class);

        $this->loader()->load($this->temp->path());
    }

    public function test_it_names_the_invalid_json_in_the_warning_when_falling_back_to_a_stale_cache(): void
    {
        $this->writeCache($this->temp->path('var/patch_metadata'), 7200);
        $this->http->respond(self::URL, 200, 'not json');

        $loaded = $this->loader()->load($this->temp->path());

        self::assertSame(RegistrySource::StaleCache, $loaded->source);
        self::assertStringContainsString(self::URL . ' did not return valid JSON.', implode("\n", $loaded->warnings));
    }

    private function loader(bool $allowInsecureLocalhost = false): RegistryLoader
    {
        return new RegistryLoader(
            new LocalFilesystem(),
            $this->http,
            new FrozenClock(new DateTimeImmutable(self::NOW)),
            $allowInsecureLocalhost,
        );
    }

    private function writeCache(string $dir, int $ageSeconds, ?string $body = null): void
    {
        mkdir($dir, 0777, true);
        $file = $dir . '/.patch_registry_cache.json';
        file_put_contents($file, $body ?? $this->body);
        touch($file, (new DateTimeImmutable(self::NOW))->getTimestamp() - $ageSeconds);
    }
}
