<?php

declare(strict_types=1);

namespace Patchline\Detection;

interface PatchBinary
{
    /**
     * Tries the diff (text, not a path) in $workDir without changing any file.
     */
    public function dryRun(string $diff, string $workDir, bool $reverse): PatchRun;

    /**
     * Applies the diff (text, not a path) in $workDir, or reverses it when $reverse is set.
     */
    public function apply(string $diff, string $workDir, bool $reverse): PatchRun;

    public function isAvailable(): bool;
}
