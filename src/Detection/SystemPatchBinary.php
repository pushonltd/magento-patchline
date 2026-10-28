<?php

declare(strict_types=1);

namespace Patchline\Detection;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\ProcessRunner;

/**
 * Runs the system patch(1). Only exit codes are interpreted, so GNU and BSD patch behave the same.
 */
final class SystemPatchBinary implements PatchBinary
{
    public function __construct(private readonly ProcessRunner $runner, private readonly string $executable = 'patch')
    {
    }

    public function dryRun(string $diff, string $workDir, bool $reverse): PatchRun
    {
        return $this->run($diff, $workDir, $reverse, true);
    }

    public function apply(string $diff, string $workDir, bool $reverse): PatchRun
    {
        return $this->run($diff, $workDir, $reverse, false);
    }

    public function isAvailable(): bool
    {
        try {
            return $this->runner->run([$this->executable, '--version'])->exitCode === 0;
        } catch (RuntimeFailure) {
            return false;
        }
    }

    /**
     * @throws RuntimeFailure when the patch program cannot be started
     */
    public function requireAvailable(): void
    {
        if ($this->isAvailable()) {
            return;
        }

        throw new RuntimeFailure(
            'The "patch" program is not installed or not on PATH. '
            . 'Install it (for example "apt-get install patch" or "brew install gpatch") and run patchline again.',
        );
    }

    private function run(string $diff, string $workDir, bool $reverse, bool $dryRun): PatchRun
    {
        $file = tempnam(sys_get_temp_dir(), 'patchline-diff-');
        if ($file === false || file_put_contents($file, $diff) === false) {
            throw new RuntimeFailure('Cannot write a temporary diff file. Check that the temp directory is writable.');
        }

        $command = [$this->executable, '-p1', '--fuzz=0', '--batch', '--forward'];
        if ($reverse) {
            $command[] = '-R';
        }
        if ($dryRun) {
            $command[] = '--dry-run';
        }
        array_push($command, '-d', $workDir, '-i', $file);

        try {
            $result = $this->runner->run($command);
        } finally {
            @unlink($file);
        }

        return new PatchRun($result->exitCode, $result->stdout . $result->stderr);
    }
}
