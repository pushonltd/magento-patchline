<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Download;

use DateTimeImmutable;
use Patchline\Download\Credentials;
use Patchline\Download\DownloadResult;
use Patchline\Download\PatchDownloader;
use Patchline\Infrastructure\HttpResponse;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Registry\RegistryPatch;
use Patchline\Tests\Double\FakeHttpClient;
use Patchline\Tests\Double\TempDirectory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PatchDownloader::class)]
#[CoversClass(DownloadResult::class)]
#[UsesClass(Credentials::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(RegistryPatch::class)]
final class PatchDownloaderTest extends TestCase
{
    private const BASE = 'https://repo.magento.com/patch';
    private const DIFF = "--- a/x\n+++ b/x\n";

    private TempDirectory $dir;
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
        $this->http = new FakeHttpClient();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    public function test_it_returns_a_cached_diff_whose_sha256_matches_without_a_network_request(): void
    {
        mkdir($this->dir->path('.patch_diffs'));
        file_put_contents($this->dir->path('.patch_diffs/A.diff'), self::DIFF);

        $result = $this->downloader()->download($this->patch());

        self::assertSame(self::DIFF, $result->content);
        self::assertNull($result->failure);
        self::assertSame([], $this->http->requests);
    }

    public function test_it_refetches_when_the_cached_diff_sha256_does_not_match(): void
    {
        mkdir($this->dir->path('.patch_diffs'));
        file_put_contents($this->dir->path('.patch_diffs/A.diff'), 'stale');
        $this->http->respond(self::BASE . '/A.diff', 200, self::DIFF);

        $result = $this->downloader()->download($this->patch());

        self::assertSame(self::DIFF, $result->content);
        self::assertSame(self::DIFF, file_get_contents($this->dir->path('.patch_diffs/A.diff')));
    }

    public function test_it_rejects_a_downloaded_diff_whose_sha256_does_not_match_the_registry(): void
    {
        $this->http->respond(self::BASE . '/A.diff', 200, 'tampered');

        $result = $this->downloader()->download($this->patch());

        self::assertNull($result->content);
        self::assertSame('sha256 mismatch', $result->failure);
        self::assertFileDoesNotExist($this->dir->path('.patch_diffs/A.diff'));
    }

    public function test_it_reports_http_status_and_network_errors_as_failures(): void
    {
        $this->http->respond(self::BASE . '/A.diff', 503);
        $this->http->fail(self::BASE . '/B.diff');

        self::assertSame('http status 503', $this->downloader()->download($this->patch())->failure);
        self::assertSame('network error', $this->downloader()->download($this->patch('B.diff'))->failure);
    }

    public function test_it_uses_the_auth_url_with_basic_credentials_for_an_entitled_patch(): void
    {
        $this->http->respond(self::BASE . '/auth/A.diff', 200, self::DIFF);

        $result = $this->downloader(new Credentials('user', 'fake-secret'))->download($this->patch('A.diff', ['ee']));

        $expectedHeaders = ['Authorization' => 'Basic ' . base64_encode('user:fake-secret')];
        self::assertSame(self::DIFF, $result->content);
        self::assertSame(
            [['url' => self::BASE . '/auth/A.diff', 'headers' => $expectedHeaders]],
            $this->http->requests,
        );
    }

    public function test_it_reports_refused_credentials(): void
    {
        $this->http->respond(self::BASE . '/auth/A.diff', 401);

        $result = $this->downloader(new Credentials('user', 'fake-secret'))->download($this->patch('A.diff', ['ee']));

        self::assertSame('credentials refused', $result->failure);
    }

    public function test_it_reports_that_credentials_are_needed_for_an_entitled_patch_when_none_are_configured(): void
    {
        $result = $this->downloader()->download($this->patch('A.diff', ['ee']));

        self::assertSame('needs repo.magento.com credentials', $result->failure);
        self::assertSame([], $this->http->requests);
    }

    public function test_it_never_sends_credentials_to_a_host_other_than_repo_magento_com(): void
    {
        $downloader = $this->downloader(new Credentials('user', 'fake-secret'), 'https://evil.example/patch');

        $result = $downloader->download($this->patch('A.diff', ['ee']));

        self::assertSame('needs repo.magento.com credentials', $result->failure);
        self::assertSame([], $this->http->requests);
    }

    public function test_it_sends_credentials_to_an_extra_origin_only_when_it_is_configured_as_trusted(): void
    {
        $this->http->respond('http://127.0.0.1:8080/patch/auth/A.diff', 200, self::DIFF);

        $result = $this->downloader(
            new Credentials('user', 'fake-secret'),
            'http://127.0.0.1:8080/patch',
            ['https://repo.magento.com', 'http://127.0.0.1:8080'],
        )->download($this->patch('A.diff', ['ee']));

        self::assertSame(self::DIFF, $result->content);
        self::assertArrayHasKey('Authorization', $this->http->requests[0]['headers']);
    }

    public function test_it_returns_verified_content_with_a_warning_when_the_diff_cache_cannot_be_written(): void
    {
        file_put_contents($this->dir->path('.patch_diffs'), 'not a directory');
        $this->http->respond(self::BASE . '/A.diff', 200, self::DIFF);

        $result = $this->downloader()->download($this->patch());

        self::assertSame(self::DIFF, $result->content);
        self::assertCount(1, $result->warnings);
    }

    public function test_it_serves_a_repeated_request_from_memory(): void
    {
        $this->http->respond(self::BASE . '/A.diff', 200, self::DIFF);
        $downloader = $this->downloader();

        $downloader->download($this->patch());
        $second = $downloader->download($this->patch());

        self::assertSame(self::DIFF, $second->content);
        self::assertCount(1, $this->http->requests);
    }

    public function test_it_skips_reading_the_cache_when_no_cache_is_requested(): void
    {
        mkdir($this->dir->path('.patch_diffs'));
        file_put_contents($this->dir->path('.patch_diffs/A.diff'), self::DIFF);
        $this->http->respond(self::BASE . '/A.diff', 200, self::DIFF);

        $this->downloader(null, self::BASE, ['https://repo.magento.com'], true)->download($this->patch());

        self::assertCount(1, $this->http->requests);
    }

    /**
     * @param list<string> $origins
     */
    private function downloader(
        ?Credentials $credentials = null,
        string $base = self::BASE,
        array $origins = ['https://repo.magento.com'],
        bool $noCache = false,
    ): PatchDownloader {
        return new PatchDownloader(
            $this->http,
            new LocalFilesystem(),
            $base,
            $this->dir->path(),
            $credentials,
            $noCache,
            $origins,
        );
    }

    /**
     * @param list<string> $entitlements
     */
    private function patch(string $file = 'A.diff', array $entitlements = []): RegistryPatch
    {
        return new RegistryPatch(
            'A',
            [],
            'CE',
            [],
            [],
            new DateTimeImmutable('2026-01-01'),
            $file,
            hash('sha256', self::DIFF),
            $entitlements,
        );
    }
}
