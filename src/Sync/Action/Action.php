<?php

declare(strict_types=1);

namespace Patchline\Sync\Action;

/** One step of a sync plan. Paths are relative to the store root. */
interface Action
{
    /** One line for text output ("would ..." wording is added by the caller). */
    public function describe(): string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
