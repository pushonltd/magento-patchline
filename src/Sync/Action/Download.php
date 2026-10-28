<?php

declare(strict_types=1);

namespace Patchline\Sync\Action;

use Patchline\Registry\RegistryPatch;

final class Download implements Action
{
    public function __construct(
        public readonly RegistryPatch $patch,
        public readonly string $targetPath,
    ) {
    }

    public function describe(): string
    {
        return sprintf('download %s to %s', $this->patch->id, $this->targetPath);
    }

    /** @return array{action: string, patch: string, path: string, sha256: string} */
    public function toArray(): array
    {
        return [
            'action' => 'download',
            'patch' => $this->patch->id,
            'path' => $this->targetPath,
            'sha256' => $this->patch->sha256,
        ];
    }
}
