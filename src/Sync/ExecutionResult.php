<?php

declare(strict_types=1);

namespace Patchline\Sync;

final class ExecutionResult
{
    /** @param list<ActionOutcome> $outcomes in plan order */
    public function __construct(public readonly array $outcomes)
    {
    }

    /** True when any action failed; `sync` exits non-zero then. */
    public function hasFailures(): bool
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->status === ActionStatus::Failed) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{status: string, message: string, action: array<string, mixed>}> */
    public function toArray(): array
    {
        return array_map(static fn (ActionOutcome $outcome): array => $outcome->toArray(), $this->outcomes);
    }
}
