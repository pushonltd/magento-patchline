<?php

declare(strict_types=1);

namespace Patchline\Sync;

use Patchline\Sync\Action\Action;

final class ActionOutcome
{
    public function __construct(
        public readonly Action $action,
        public readonly ActionStatus $status,
        public readonly string $message,
    ) {
    }

    /** @return array{status: string, message: string, action: array<string, mixed>} */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'message' => $this->message, 'action' => $this->action->toArray()];
    }
}
