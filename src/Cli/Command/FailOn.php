<?php

declare(strict_types=1);

namespace Patchline\Cli\Command;

use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\Cli\Config;

final class FailOn
{
    public static function exitCode(Report $report, Config $config): int
    {
        $states = array_map(static fn (string $state): PatchState => PatchState::from($state), $config->failOnStates());

        return $report->hasFindings($states) ? 1 : 0;
    }
}
