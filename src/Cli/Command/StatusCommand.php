<?php

declare(strict_types=1);

namespace Patchline\Cli\Command;

use Patchline\Cli\Config;

final class StatusCommand implements Command
{
    public function __construct(private readonly Config $config, private readonly Inspector $inspector)
    {
    }

    public function run(): CommandResult
    {
        $report = $this->inspector->inspect()->report;

        return new CommandResult($report, FailOn::exitCode($report, $this->config));
    }
}
