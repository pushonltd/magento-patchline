<?php

declare(strict_types=1);

namespace Patchline\Sync\Action;

final class Remove implements Action
{
    public function __construct(
        public readonly string $path,
        public readonly string $sha256,
    ) {
    }

    public function describe(): string
    {
        return sprintf('remove %s', $this->path);
    }

    /** @return array{action: string, path: string, sha256: string} */
    public function toArray(): array
    {
        return ['action' => 'remove', 'path' => $this->path, 'sha256' => $this->sha256];
    }
}
