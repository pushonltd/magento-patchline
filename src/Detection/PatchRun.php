<?php

declare(strict_types=1);

namespace Patchline\Detection;

final class PatchRun
{
    public readonly bool $succeeded;

    public function __construct(
        public readonly int $exitCode,
        public readonly string $output = '',
    ) {
        $this->succeeded = $exitCode === 0;
    }
}
