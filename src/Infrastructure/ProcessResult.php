<?php

declare(strict_types=1);

namespace Patchline\Infrastructure;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }
}
