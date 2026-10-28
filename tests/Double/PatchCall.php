<?php

declare(strict_types=1);

namespace Patchline\Tests\Double;

final class PatchCall
{
    public function __construct(
        public readonly string $diff,
        public readonly string $workDir,
        public readonly bool $reverse,
        public readonly bool $dryRun,
    ) {
    }
}
