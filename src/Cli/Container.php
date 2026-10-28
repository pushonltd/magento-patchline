<?php

declare(strict_types=1);

namespace Patchline\Cli;

use Patchline\Cli\Command\Command;
use Patchline\Cli\Command\Inspector;
use Patchline\Cli\Command\StatusCommand;
use Patchline\Cli\Command\SyncCommand;
use Patchline\Cli\Command\VerifyCommand;
use Patchline\Cli\Output\Formatter;
use Patchline\Cli\Output\GithubFormatter;
use Patchline\Cli\Output\JsonFormatter;
use Patchline\Cli\Output\JunitFormatter;
use Patchline\Cli\Output\Style;
use Patchline\Cli\Output\TextFormatter;
use Patchline\CrossCheck\PatchStatusCrossCheck;
use Patchline\Detection\DiffParser;
use Patchline\Detection\SystemPatchBinary;
use Patchline\Infrastructure\Clock;
use Patchline\Infrastructure\Filesystem;
use Patchline\Infrastructure\GuzzleHttpClient;
use Patchline\Infrastructure\HttpClient;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Infrastructure\ProcessRunner;
use Patchline\Infrastructure\SymfonyProcessRunner;
use Patchline\Infrastructure\SystemClock;
use Patchline\Rewind\QueueRewinder;
use Patchline\Sync\Executor;
use Patchline\Verify\QueueReplayer;

/**
 * Builds the object graph. The seams (filesystem, HTTP, processes, clock) can be replaced in tests.
 */
final class Container
{
    public function __construct(
        private readonly Filesystem $filesystem = new LocalFilesystem(),
        private readonly HttpClient $http = new GuzzleHttpClient(),
        private readonly ProcessRunner $processes = new SymfonyProcessRunner(),
        private readonly Clock $clock = new SystemClock(),
    ) {
    }

    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }

    public function patchBinary(): SystemPatchBinary
    {
        return new SystemPatchBinary($this->processes);
    }

    public function queueRewinder(): QueueRewinder
    {
        return new QueueRewinder($this->patchBinary(), new DiffParser(), $this->filesystem);
    }

    /**
     * @param array<string, string> $env
     */
    public function command(Config $config, array $env): Command
    {
        $inspector = $this->inspector($config, $env);

        return match ($config->command()) {
            'sync' => new SyncCommand(
                $config,
                $inspector,
                new Executor($this->filesystem, $inspector->downloader(), $config->root()),
            ),
            'verify' => new VerifyCommand(
                $config,
                $inspector,
                new QueueReplayer($inspector->patchBinary(), new DiffParser(), $this->filesystem),
            ),
            default => new StatusCommand($config, $inspector),
        };
    }

    /**
     * @param array<string, string> $env
     */
    private function inspector(Config $config, array $env): Inspector
    {
        return new Inspector($config, $env, $this->filesystem, $this->http, $this->processes, $this->clock);
    }

    public function crossCheck(): PatchStatusCrossCheck
    {
        return new PatchStatusCrossCheck($this->processes, $this->filesystem);
    }

    public function formatter(Config $config, Style $style): Formatter
    {
        return match ($config->format()) {
            OutputFormat::Text => new TextFormatter($style),
            OutputFormat::Json => new JsonFormatter(),
            OutputFormat::Github => new GithubFormatter(),
            OutputFormat::Junit => new JunitFormatter(),
        };
    }
}
