<?php

declare(strict_types=1);

namespace Patchline\Download;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\Filesystem;
use Patchline\Infrastructure\HttpClient;
use Patchline\Registry\RegistryPatch;

final class PatchDownloader
{
    /** @var array<string, string> verified content by file name */
    private array $verified = [];

    /**
     * @param list<string> $credentialOrigins scheme + host (+ port) that may receive credentials
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly Filesystem $filesystem,
        private readonly string $baseUrl,
        private readonly string $cacheDir,
        private readonly ?Credentials $credentials = null,
        private readonly bool $noCache = false,
        private readonly array $credentialOrigins = ['https://repo.magento.com'],
    ) {
    }

    public function download(RegistryPatch $patch): DownloadResult
    {
        if (isset($this->verified[$patch->fileName])) {
            return DownloadResult::success($this->verified[$patch->fileName]);
        }

        $cachePath = $this->cacheDir . '/.patch_diffs/' . $patch->fileName;
        $warnings = [];
        if (!$this->noCache && $this->filesystem->isFile($cachePath)) {
            $cached = $this->filesystem->read($cachePath);
            if (self::matches($cached, $patch)) {
                return $this->remember($patch, $cached);
            }
        }
        if ($this->filesystem->isFile($cachePath)) {
            $warning = $this->tryCache(fn () => $this->filesystem->delete($cachePath), 'remove stale', $cachePath);
            if ($warning !== null) {
                $warnings[] = $warning;
            }
        }

        $fetched = $this->fetch($patch);
        if ($fetched instanceof DownloadResult) {
            return $fetched;
        }
        if (!self::matches($fetched, $patch)) {
            return DownloadResult::failure('sha256 mismatch');
        }

        $warning = $this->tryCache(fn () => $this->filesystem->writeAtomic($cachePath, $fetched), 'write', $cachePath);
        if ($warning !== null) {
            $warnings[] = $warning;
        }
        $this->verified[$patch->fileName] = $fetched;

        return DownloadResult::success($fetched, $warnings);
    }

    private function remember(RegistryPatch $patch, string $content): DownloadResult
    {
        $this->verified[$patch->fileName] = $content;

        return DownloadResult::success($content);
    }

    private function fetch(RegistryPatch $patch): string|DownloadResult
    {
        $entitled = $patch->entitlements !== [];
        $headers = [];
        $url = $this->baseUrl . '/' . $patch->fileName;
        if ($entitled) {
            if ($this->credentials === null || !$this->isTrusted($this->baseUrl)) {
                return DownloadResult::failure('needs repo.magento.com credentials');
            }
            $url = $this->baseUrl . '/auth/' . $patch->fileName;
            $headers['Authorization'] = $this->credentials->authorizationHeader();
        }

        try {
            $response = $this->http->get($url, $headers);
        } catch (RuntimeFailure) {
            return DownloadResult::failure('network error');
        }

        if ($entitled && in_array($response->status, [401, 403], true)) {
            return DownloadResult::failure('credentials refused');
        }
        if ($response->status !== 200) {
            return DownloadResult::failure('http status ' . $response->status);
        }

        return $response->body;
    }

    private function isTrusted(string $url): bool
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = strtolower($parts['scheme'] . '://' . $parts['host'] . $port);

        return in_array($origin, array_map('strtolower', $this->credentialOrigins), true);
    }

    private static function matches(string $content, RegistryPatch $patch): bool
    {
        return hash_equals(strtolower($patch->sha256), hash('sha256', $content));
    }

    private function tryCache(callable $operation, string $action, string $path): ?string
    {
        try {
            $operation();
        } catch (RuntimeFailure) {
            return sprintf('Could not %s the diff cache file %s; continuing without it.', $action, $path);
        }

        return null;
    }
}
