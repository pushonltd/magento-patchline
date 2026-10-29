<?php

declare(strict_types=1);

namespace Patchline\Update;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\Clock;
use Patchline\Infrastructure\Filesystem;
use Patchline\Infrastructure\HttpClient;
use Throwable;

/**
 * Asks the release feed for a newer version, at most once per day. Never throws.
 */
final class UpdateChecker
{
    public const CACHE_FILE = '.patchline_update_check.json';
    private const CACHE_TTL_SECONDS = 86400;

    private readonly ReleaseFeedParser $parser;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly HttpClient $http,
        private readonly Clock $clock,
    ) {
        $this->parser = new ReleaseFeedParser();
    }

    /**
     * @return string|null the newer stable version, or null when there is none or the check failed
     */
    public function latestNewerThan(string $current, string $url, string $cacheDir, bool $useCache): ?string
    {
        if (ReleaseFeed::releaseVersion($current) === null) {
            return null;
        }

        $cacheFile = $cacheDir . '/' . self::CACHE_FILE;
        $now = $this->clock->now()->getTimestamp();
        $cached = $useCache ? $this->readCache($cacheFile, $now) : null;
        if ($cached !== null) {
            return UpdateAvailability::newerThan($current, $cached['latest']);
        }

        $latest = $this->fetchLatest($url);
        if ($useCache) {
            $this->writeCache($cacheDir, $cacheFile, $now, $latest);
        }

        return UpdateAvailability::newerThan($current, $latest);
    }

    private function fetchLatest(string $url): ?string
    {
        try {
            $response = $this->http->get($url);

            return $response->status === 200 ? $this->parser->latestStable($response->body) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{latest: string|null}|null null when there is no usable cache
     */
    private function readCache(string $file, int $now): ?array
    {
        try {
            $data = json_decode($this->filesystem->read($file), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data) || !isset($data['checked_at']) || !is_int($data['checked_at'])) {
            return null;
        }
        $age = $now - $data['checked_at'];
        if ($age < 0 || $age >= self::CACHE_TTL_SECONDS) {
            return null;
        }
        $latest = $data['latest'] ?? null;
        if ($latest !== null && (!is_string($latest) || ReleaseFeed::releaseVersion($latest) === null)) {
            return null;
        }
        if (!array_key_exists('latest', $data)) {
            return null;
        }

        return ['latest' => $latest];
    }

    private function writeCache(string $dir, string $file, int $now, ?string $latest): void
    {
        try {
            $this->filesystem->makeDirectory($dir);
            $this->filesystem->replaceAtomic(
                $file,
                json_encode(['checked_at' => $now, 'latest' => $latest], JSON_THROW_ON_ERROR),
            );
        } catch (RuntimeFailure | \JsonException) {
            // A read-only store must not turn an update check into an error.
        }
    }
}
