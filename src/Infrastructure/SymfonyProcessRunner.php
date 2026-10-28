<?php

declare(strict_types=1);

namespace Patchline\Infrastructure;

use Patchline\Exception\RuntimeFailure;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function __construct(private readonly ?float $timeout = null)
    {
    }

    public function run(array $command, ?string $stdin = null, ?string $cwd = null): ProcessResult
    {
        $process = new Process($command, $cwd, null, $stdin, $this->timeout);

        try {
            $process->run();
        } catch (RuntimeException $e) {
            throw new RuntimeFailure(
                sprintf('Cannot start "%s". Check that it is installed and the directory exists.', $command[0] ?? ''),
                0,
                $e,
            );
        }

        return new ProcessResult($process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput());
    }
}
