<?php

declare(strict_types=1);

namespace Patchline\Cli\Command;

interface Command
{
    public function run(): CommandResult;
}
