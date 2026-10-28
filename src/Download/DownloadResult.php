<?php

declare(strict_types=1);

namespace Patchline\Download;

final class DownloadResult
{
    /**
     * @param list<string> $warnings
     */
    private function __construct(
        public readonly ?string $content,
        public readonly ?string $failure,
        public readonly array $warnings,
    ) {
    }

    /**
     * @param list<string> $warnings
     */
    public static function success(string $content, array $warnings = []): self
    {
        return new self($content, null, $warnings);
    }

    public static function failure(string $reason): self
    {
        return new self(null, $reason, []);
    }
}
