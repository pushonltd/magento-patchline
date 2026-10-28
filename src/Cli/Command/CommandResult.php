<?php

declare(strict_types=1);

namespace Patchline\Cli\Command;

use Patchline\Analysis\Report;
use Patchline\Sync\ExecutionResult;
use Patchline\Verify\VerifyResult;

final class CommandResult
{
    public function __construct(
        public readonly Report $report,
        public readonly int $exitCode,
        public readonly ?ExecutionResult $sync = null,
        public readonly ?VerifyResult $verify = null,
        public readonly bool $dryRun = false,
    ) {
    }
}
