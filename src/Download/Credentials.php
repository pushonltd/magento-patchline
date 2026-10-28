<?php

declare(strict_types=1);

namespace Patchline\Download;

use Closure;

/**
 * repo.magento.com credentials. The secret lives inside a closure so that string conversion, dumps,
 * `var_export` and JSON encoding cannot reveal it.
 */
final class Credentials
{
    private readonly Closure $header;

    public function __construct(string $username, string $password)
    {
        $this->header = static fn (): string => 'Basic ' . base64_encode($username . ':' . $password);
    }

    public function authorizationHeader(): string
    {
        return ($this->header)();
    }

    public function __toString(): string
    {
        return '[redacted credentials]';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['credentials' => '[redacted]'];
    }
}
