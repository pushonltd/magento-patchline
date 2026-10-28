<?php

declare(strict_types=1);

namespace Patchline\Sync;

use Patchline\Queue\QueueEntry;
use Patchline\Sync\Action\Action;
use Patchline\Sync\Action\Warn;

/** What `sync` will do: removals, renames, downloads (in that order), then warnings. */
final class Plan
{
    /**
     * @param list<Action> $actions
     * @param list<QueueEntry> $resultingQueue the queue `ece-patches apply` would see after the actions
     */
    public function __construct(
        public readonly array $actions,
        private readonly array $resultingQueue,
    ) {
    }

    /** @return list<QueueEntry> */
    public function resultingQueue(): array
    {
        return $this->resultingQueue;
    }

    /** True when the plan changes files (warnings alone change nothing). */
    public function hasChanges(): bool
    {
        foreach ($this->actions as $action) {
            if (!$action instanceof Warn) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Warn> */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (Action $action): bool => $action instanceof Warn,
        ));
    }

    /** @return array{actions: list<array<string, mixed>>, resulting_queue: list<array{position: int, path: string, sha256: string}>} */
    public function toArray(): array
    {
        return [
            'actions' => array_map(static fn (Action $action): array => $action->toArray(), $this->actions),
            'resulting_queue' => array_map(
                static fn (QueueEntry $entry): array => [
                    'position' => $entry->position,
                    'path' => $entry->path,
                    'sha256' => $entry->sha256,
                ],
                $this->resultingQueue,
            ),
        ];
    }
}
