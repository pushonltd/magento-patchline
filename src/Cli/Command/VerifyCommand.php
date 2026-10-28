<?php

declare(strict_types=1);

namespace Patchline\Cli\Command;

use Patchline\Cli\Config;
use Patchline\Verify\QueueReplayer;

final class VerifyCommand implements Command
{
    public function __construct(
        private readonly Config $config,
        private readonly Inspector $inspector,
        private readonly QueueReplayer $replayer,
    ) {
    }

    public function run(): CommandResult
    {
        $inspection = $this->inspector->inspect();
        $verify = $this->replayer->replay($inspection->installation, $inspection->report->queue());
        $failed = !$verify->success || FailOn::exitCode($inspection->report, $this->config) === 1;

        return new CommandResult($inspection->report, $failed ? 1 : 0, null, $verify);
    }
}
