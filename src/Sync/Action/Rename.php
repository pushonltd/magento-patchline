<?php

declare(strict_types=1);

namespace Patchline\Sync\Action;

final class Rename implements Action
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $sha256,
    ) {
    }

    public function describe(): string
    {
        return sprintf('rename %s to %s', $this->from, $this->to);
    }

    /** @return array{action: string, from: string, to: string, sha256: string} */
    public function toArray(): array
    {
        return ['action' => 'rename', 'from' => $this->from, 'to' => $this->to, 'sha256' => $this->sha256];
    }
}
