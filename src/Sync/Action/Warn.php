<?php

declare(strict_types=1);

namespace Patchline\Sync\Action;

final class Warn implements Action
{
    public function __construct(
        public readonly string $message,
        public readonly ?string $patchId = null,
    ) {
    }

    public function describe(): string
    {
        return $this->message;
    }

    /** @return array{action: string, message: string, patch: string|null} */
    public function toArray(): array
    {
        return ['action' => 'warn', 'message' => $this->message, 'patch' => $this->patchId];
    }
}
