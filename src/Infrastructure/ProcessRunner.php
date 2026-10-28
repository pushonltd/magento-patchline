<?php

declare(strict_types=1);

namespace Patchline\Infrastructure;

interface ProcessRunner
{
    /**
     * @param list<string> $command executable and arguments, run without a shell
     *
     * @throws \Patchline\Exception\RuntimeFailure when the process cannot be started
     */
    public function run(array $command, ?string $stdin = null, ?string $cwd = null): ProcessResult;
}
