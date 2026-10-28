<?php

declare(strict_types=1);

namespace Patchline\Cli\Output;

use Patchline\Analysis\Report;
use Patchline\CrossCheck\CrossCheckResult;
use Patchline\Sync\ExecutionResult;
use Patchline\Verify\VerifyResult;

/**
 * Renders a Report, plus the result of `sync` or `verify` when that command ran.
 *
 * The caller (Application, task 021) picks the implementation from OutputFormat and writes
 * FormattedOutput::$document to stdout. Formatters hold no I/O.
 */
interface Formatter
{
    /**
     * @param ExecutionResult|null $sync result of `sync` (the report is the post-sync report)
     * @param bool $dryRun whether `sync` only planned its actions
     */
    public function format(
        Report $report,
        ?ExecutionResult $sync = null,
        ?VerifyResult $verify = null,
        bool $dryRun = false,
        ?CrossCheckResult $crossCheck = null,
    ): FormattedOutput;
}
