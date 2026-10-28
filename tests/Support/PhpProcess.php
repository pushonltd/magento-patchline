<?php

declare(strict_types=1);

namespace Patchline\Tests\Support;

use RuntimeException;

final class PhpProcess
{
    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment added to the inherited environment
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public static function run(array $arguments, ?string $cwd = null, array $environment = []): array
    {
        $command = array_merge([PHP_BINARY], $arguments);
        $env = $environment === [] ? null : array_merge(getenv(), $environment);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start PHP process.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => proc_close($process)];
    }
}
