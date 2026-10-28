<?php

declare(strict_types=1);

namespace Patchline\Registry;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\Clock;
use Patchline\Infrastructure\Filesystem;
use Patchline\Infrastructure\HttpClient;

/**
 * Loads the registry from an explicit source, the cache shared with patch-status, or the remote.
 */
final class RegistryLoader
{
    public const DEFAULT_URL = 'https://repo.magento.com/patch/patch-registry.json';
    public const CACHE_FILE = '.patch_registry_cache.json';
    private const CACHE_TTL_SECONDS = 3600;

    private readonly RegistryParser $parser;

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly bool $allowInsecureLocalhost = false,
    ) {
        $this->parser = new RegistryParser($allowInsecureLocalhost);
    }

    /**
     * @param string|null $registry explicit URL or local path; bypasses the shared cache
     * @param string|null $cacheDir defaults to `<root>/var/patch_metadata`
     *
     * @throws RuntimeFailure
     */
    public function load(
        string $root,
        ?string $registry = null,
        ?string $cacheDir = null,
        bool $noCache = false,
    ): LoadedRegistry {
        if ($registry !== null) {
            return $this->loadExplicit($registry);
        }

        $cacheFile = ($cacheDir ?? rtrim($root, '/') . '/var/patch_metadata') . '/' . self::CACHE_FILE;
        $cached = $this->readCache($cacheFile);
        if (!$noCache && $cached !== null && $this->ageSeconds($cacheFile) < self::CACHE_TTL_SECONDS) {
            return $this->build($cached, RegistrySource::Cache, []);
        }

        $warnings = [];
        $body = $this->fetch(self::DEFAULT_URL, $warnings);
        if ($body !== null) {
            $this->writeCache($cacheFile, $body, $warnings);

            return $this->build($this->decode($body), RegistrySource::Remote, $warnings);
        }

        if ($noCache || $cached === null) {
            throw new RuntimeFailure(
                'Cannot load the patch registry: the remote is unreachable and no usable cache exists. '
                . 'Check your network connection or pass --registry with a local file.',
            );
        }
        $warnings[] = sprintf(
            'Using a stale registry cache that is %d hours old because the remote is unreachable.',
            intdiv($this->ageSeconds($cacheFile), 3600),
        );

        return $this->build($cached, RegistrySource::StaleCache, $warnings);
    }

    private function loadExplicit(string $registry): LoadedRegistry
    {
        if (preg_match('~^https?://~', $registry) === 1) {
            $this->assertSecureUrl($registry);
            $warnings = [];
            $body = $this->fetch($registry, $warnings);
            if ($body === null) {
                throw new RuntimeFailure(implode(' ', $warnings));
            }

            return $this->build($this->decode($body), RegistrySource::Remote, []);
        }
        if (!$this->filesystem->isFile($registry)) {
            throw new RuntimeFailure(sprintf('The registry file %s does not exist.', $registry));
        }

        return $this->build($this->decode($this->filesystem->read($registry)), RegistrySource::File, []);
    }

    private function assertSecureUrl(string $url): void
    {
        if (str_starts_with($url, 'https://')) {
            return;
        }
        if ($this->allowInsecureLocalhost && preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?(/|$)~', $url) === 1) {
            return;
        }

        throw new RuntimeFailure(sprintf('The registry URL "%s" must use https.', $url));
    }

    /**
     * @param list<string> $warnings
     */
    private function fetch(string $url, array &$warnings): ?string
    {
        try {
            $response = $this->http->get($url);
        } catch (RuntimeFailure $e) {
            $warnings[] = sprintf('Could not fetch the registry: %s', $e->getMessage());

            return null;
        }
        if ($response->status !== 200) {
            $warnings[] = sprintf('Could not fetch the registry: %s answered HTTP %d.', $url, $response->status);

            return null;
        }
        if ($this->decode($response->body) === null) {
            $warnings[] = sprintf('Could not fetch the registry: %s did not return valid JSON.', $url);

            return null;
        }

        return $response->body;
    }

    private function decode(string $body): mixed
    {
        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    private function readCache(string $file): mixed
    {
        if (!$this->filesystem->isFile($file)) {
            return null;
        }

        return $this->decode($this->filesystem->read($file));
    }

    private function ageSeconds(string $file): int
    {
        return max(0, $this->clock->now()->getTimestamp() - $this->filesystem->modifiedAt($file)->getTimestamp());
    }

    /**
     * @param list<string> $warnings
     */
    private function writeCache(string $file, string $body, array &$warnings): void
    {
        try {
            $this->filesystem->makeDirectory(dirname($file));
            $this->filesystem->replaceAtomic($file, $body);
        } catch (RuntimeFailure $e) {
            $warnings[] = sprintf('Could not write the registry cache: %s', $e->getMessage());
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function build(mixed $data, RegistrySource $source, array $warnings): LoadedRegistry
    {
        $result = $this->parser->parse($data);

        return new LoadedRegistry($result->registry, $source, [...$warnings, ...$result->warnings]);
    }
}
