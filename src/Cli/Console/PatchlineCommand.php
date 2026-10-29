<?php

declare(strict_types=1);

namespace Patchline\Cli\Console;

use Patchline\Cli\Command\CommandResult;
use Patchline\Cli\Config;
use Patchline\Cli\ConfigResolver;
use Patchline\Cli\Container;
use Patchline\Cli\Output\GithubFormatter;
use Patchline\Cli\Output\Style;
use Patchline\Cli\OutputFormat;
use Patchline\CrossCheck\CrossCheckResult;
use Patchline\Update\UpdateNotice;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One of status, sync or verify: resolves the configuration from the input, runs the matching patchline
 * command, writes the report to stdout and returns the exit code. Messages go to stderr.
 */
final class PatchlineCommand extends Command
{
    /**
     * @param array<string, string> $env
     */
    public function __construct(
        string $name,
        private readonly string $summary,
        private readonly bool $syncOptions,
        private readonly Container $container,
        private readonly array $env,
        private readonly string $cwd,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription($this->summary)
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Store root (default: current directory).')
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'text, json, github or junit (default: text, github inside GitHub Actions).',
            )
            ->addOption(
                'fail-on',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated states that make the exit code 1 (default: missing,duplicate,misordered;'
                . ' "cross-check" is also accepted).',
            )
            ->addOption(
                'registry',
                null,
                InputOption::VALUE_REQUIRED,
                'Use this registry URL or file instead of repo.magento.com.',
            )
            ->addOption(
                'cache-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Cache for the registry and diffs (default: <root>/var/patch_metadata).',
            )
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Ignore cached registry and diffs.')
            ->addOption(
                'include-entitled',
                null,
                InputOption::VALUE_NONE,
                'Also handle patches that need an entitlement (ee, b2b).',
            )
            ->addOption('cross-check', null, InputOption::VALUE_NONE, 'Compare with vendor/bin/patch-status.')
            ->addOption(
                'no-update-check',
                null,
                InputOption::VALUE_NONE,
                'Do not check for a newer Patchline release.',
            );

        if (!$this->syncOptions) {
            return;
        }

        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing anything.')
            ->addOption('adopt', null, InputOption::VALUE_NONE, 'Rename existing hotfix files to the registry naming.')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Remove hotfix files that are no longer needed.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = (new ConfigResolver($this->container->filesystem()))
            ->resolve($input, (string) $this->getName(), $this->env, $this->cwd);
        $result = $this->container->command($config, $this->env)->run();
        $crossCheck = $config->crossCheck()
            ? $this->container->crossCheck()->check($result->report, $config->root(), $config->noCache())
            : null;
        $this->write($config, $result, $crossCheck, $output);
        try {
            $this->noticeUpdate($config, $output);
        } catch (\Throwable) {
            // The report is already written: a failing update notice must never change the outcome.
        }

        $failedCrossCheck = $config->failOnCrossCheck() && $crossCheck !== null && $crossCheck->hasDisagreements();

        return $failedCrossCheck ? max(1, $result->exitCode) : $result->exitCode;
    }

    private function write(
        Config $config,
        CommandResult $result,
        ?CrossCheckResult $crossCheck,
        OutputInterface $output,
    ): void {
        $formatter = $this->container->formatter($config, $this->style($config, $output));
        $document = $formatter->format($result->report, $result->sync, $result->verify, $result->dryRun, $crossCheck);
        $output->write($document->document, false, OutputInterface::OUTPUT_RAW);

        $summary = $document->artefacts[GithubFormatter::STEP_SUMMARY] ?? null;
        $target = $this->env['GITHUB_STEP_SUMMARY'] ?? '';
        if ($summary === null || $target === '') {
            return;
        }

        $this->appendSummary($target, $summary, $output);
    }

    private function noticeUpdate(Config $config, OutputInterface $output): void
    {
        if (!$config->updateCheck()) {
            return;
        }
        $current = $this->container->version();
        $latest = $this->container->updateChecker()->latestNewerThan(
            $current,
            $config->updateUrl(),
            $config->cacheDir() ?? $config->root() . '/var/patch_metadata',
            !$config->noCache(),
        );
        if ($latest === null) {
            return;
        }

        $notice = UpdateNotice::render(
            $current,
            $latest,
            $this->container->pharPath(),
            $this->cwd,
            $this->container->devRequirement(),
            $config->format(),
        );
        $target = $config->format() === OutputFormat::Github || !$output instanceof ConsoleOutputInterface
            ? $output
            : $output->getErrorOutput();
        $target->write($notice, false, OutputInterface::OUTPUT_RAW);
    }

    private function style(Config $config, OutputInterface $output): Style
    {
        $colour = $output->isDecorated() && !$config->noAnsi() && ($this->env['NO_COLOR'] ?? '') === '';

        return $colour ? Style::enabled() : Style::disabled();
    }

    private function appendSummary(string $path, string $summary, OutputInterface $output): void
    {
        $writable = is_dir(dirname($path)) && (!file_exists($path) || is_writable($path));
        if ($writable && file_put_contents($path, $summary, FILE_APPEND) !== false) {
            return;
        }
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errors->write(sprintf("Could not write the job summary to %s.\n", $path), false, OutputInterface::OUTPUT_RAW);
    }
}
