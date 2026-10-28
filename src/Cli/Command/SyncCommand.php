<?php

declare(strict_types=1);

namespace Patchline\Cli\Command;

use Patchline\Cli\Config;
use Patchline\Sync\Executor;
use Patchline\Sync\Planner;
use Patchline\Sync\SyncOptions;

final class SyncCommand implements Command
{
    public function __construct(
        private readonly Config $config,
        private readonly Inspector $inspector,
        private readonly Executor $executor,
    ) {
    }

    public function run(): CommandResult
    {
        $before = $this->inspector->inspect()->report;
        $plan = (new Planner())->plan(
            $before,
            new SyncOptions($this->config->adopt(), $this->config->prune(), $this->config->includeEntitled()),
        );
        $result = $this->executor->execute($plan, $this->config->dryRun());

        if ($this->config->dryRun()) {
            return new CommandResult($before, FailOn::exitCode($before, $this->config), $result, null, true);
        }

        $after = $this->inspector->inspect()->report;
        $exit = $result->hasFailures() ? 1 : FailOn::exitCode($after, $this->config);

        return new CommandResult($after, $exit, $result);
    }
}
