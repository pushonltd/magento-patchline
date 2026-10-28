<?php

declare(strict_types=1);

namespace Patchline\Cli;

use Patchline\Cli\Console\ConsoleApplication;
use Patchline\Cli\Console\PatchlineCommand;
use Patchline\Cli\Console\RewindCommand;
use Patchline\Cli\Console\StreamConsoleOutput;
use Patchline\Exception\RuntimeFailure;
use Patchline\Exception\UsageException;
use Patchline\PackageVersion;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Throwable;

/**
 * Entry point of the command line tool, built on Symfony Console. Takes everything it needs as arguments
 * (no globals) and returns the exit code: 0 ok, 1 findings, 2 usage error, 3 runtime error.
 */
final class Application
{
    private const HINT = "Run patchline --help for usage.\n";

    public function __construct(private readonly Container $container = new Container())
    {
    }

    /**
     * @param list<string> $argv arguments without the script name
     * @param array<string, string> $env
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(array $argv, array $env, string $cwd, $stdout, $stderr): int
    {
        $colour = stream_isatty($stdout) && ($env['NO_COLOR'] ?? '') === '';
        $output = new StreamConsoleOutput($stdout, $stderr, $colour);

        try {
            return $this->dispatch($argv, $env, $cwd, $output);
        } catch (UsageException | ExceptionInterface $e) {
            $this->error($output, $e->getMessage() . "\n" . self::HINT);

            return 2;
        } catch (RuntimeFailure $e) {
            $this->error($output, $e->getMessage() . "\n");

            return 3;
        } catch (Throwable $e) {
            $message = 'Unexpected error: ' . $e->getMessage() . "\n";
            if (($env['PATCHLINE_DEBUG'] ?? '') === '1') {
                $message .= $e->getTraceAsString() . "\n";
            }
            $this->error($output, $message);

            return 3;
        }
    }

    private function error(StreamConsoleOutput $output, string $message): void
    {
        $output->getErrorOutput()->write($message, false, StreamConsoleOutput::OUTPUT_RAW);
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     */
    private function dispatch(array $argv, array $env, string $cwd, StreamConsoleOutput $output): int
    {
        $application = $this->console($env, $cwd);
        $input = new ArgvInput(['patchline', ...$argv]);
        $input->setInteractive(false);

        $asksForInformation = $input->hasParameterOption(['--help', '-h', '--version', '-V'], true);
        if ($input->getFirstArgument() === null && !$asksForInformation) {
            $application->run(new ArrayInput(['command' => 'list']), $output->getErrorOutput());

            return 2;
        }

        return $application->run($input, $output);
    }

    /**
     * The Console application with the status, sync, verify and rewind commands.
     *
     * @param array<string, string> $env
     */
    public function console(array $env, string $cwd): ConsoleApplication
    {
        $commands = [
            'status' => ['Show which registry patches are applied, queued or missing.', false],
            'sync' => ['Download missing patches into m2-hotfixes/.', true],
            'verify' => ['Replay the patch queue on a copy of the store (needs the patch program).', false],
        ];

        $instances = [];
        foreach ($commands as $name => [$summary, $syncOptions]) {
            $instances[] = new PatchlineCommand($name, $summary, $syncOptions, $this->container, $env, $cwd);
        }

        $instances[] = new RewindCommand($this->container, $env, $cwd);

        return new ConsoleApplication(PackageVersion::current(), $instances);
    }
}
