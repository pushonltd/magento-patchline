<?php

declare(strict_types=1);

namespace Patchline\Tests\Support;

use RuntimeException;

final class PhpProcess
{
    /**
     * Variables that change Patchline's behaviour. They are never inherited from the environment the tests run
     * in (a CI runner sets GITHUB_ACTIONS, for example), so child processes see only what a test passes in.
     */
    private const ISOLATED = ['GITHUB_ACTIONS', 'GITHUB_STEP_SUMMARY', 'NO_COLOR', 'COMPOSER_AUTH'];

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment added to the inherited environment
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public static function run(array $arguments, ?string $cwd = null, array $environment = []): array
    {
        $command = array_merge([PHP_BINARY], $arguments);
        $env = array_merge(self::inheritedEnvironment(), ['PATCHLINE_NO_UPDATE_CHECK' => '1'], $environment);
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

    /**
     * @return array<string, string>
     */
    private static function inheritedEnvironment(): array
    {
        $inherited = [];
        foreach (getenv() as $name => $value) {
            if (in_array($name, self::ISOLATED, true) || str_starts_with($name, 'PATCHLINE_')) {
                continue;
            }
            $inherited[$name] = $value;
        }

        return $inherited;
    }
}
