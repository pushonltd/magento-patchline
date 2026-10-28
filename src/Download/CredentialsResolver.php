<?php

declare(strict_types=1);

namespace Patchline\Download;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\Filesystem;

final class CredentialsResolver
{
    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    /**
     * @param array<string, string> $environment
     */
    public function resolve(string $root, array $environment): ?Credentials
    {
        foreach ($this->sources($root, $environment) as $json) {
            $credentials = self::extract($json);
            if ($credentials !== null) {
                return $credentials;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $environment
     *
     * @return iterable<string>
     */
    private function sources(string $root, array $environment): iterable
    {
        if (isset($environment['COMPOSER_AUTH'])) {
            yield $environment['COMPOSER_AUTH'];
        }

        $files = [$root . '/auth.json'];
        if (isset($environment['COMPOSER_HOME'])) {
            $files[] = $environment['COMPOSER_HOME'] . '/auth.json';
        }
        if (isset($environment['HOME'])) {
            $files[] = $environment['HOME'] . '/.composer/auth.json';
            $files[] = $environment['HOME'] . '/.config/composer/auth.json';
        }

        foreach ($files as $file) {
            $contents = $this->readOrNull($file);
            if ($contents === null) {
                continue;
            }

            yield $contents;
        }
    }

    private function readOrNull(string $file): ?string
    {
        if (!$this->filesystem->isFile($file)) {
            return null;
        }

        try {
            return $this->filesystem->read($file);
        } catch (RuntimeFailure) {
            return null;
        }
    }

    private static function extract(string $json): ?Credentials
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $hosts = is_array($data) ? ($data['http-basic'] ?? null) : null;
        $entry = is_array($hosts) ? ($hosts['repo.magento.com'] ?? null) : null;
        if (!is_array($entry)) {
            return null;
        }
        $username = $entry['username'] ?? null;
        $password = $entry['password'] ?? null;

        return is_string($username) && is_string($password) && $username !== '' && $password !== ''
            ? new Credentials($username, $password)
            : null;
    }
}
