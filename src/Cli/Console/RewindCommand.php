<?php

declare(strict_types=1);

namespace Patchline\Cli\Console;

use Patchline\Cli\ConfigResolver;
use Patchline\Cli\Container;
use Patchline\Cli\Output\RewindTextRenderer;
use Patchline\Cli\Output\Style;
use Patchline\Exception\RuntimeFailure;
use Patchline\Installation\InstallationReader;
use Patchline\Queue\QueueReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `patchline rewind`: reverses the patch queue in vendor/ so `ece-patches apply` can run again. Needs neither the
 * registry nor the network; ignores `extra.patchline` and PATCHLINE_* variables. Exit code 0 ok, 1 blocked.
 */
final class RewindCommand extends Command
{
    private const CLOUD_PATCHES_DIRECTORY = 'vendor/magento/magento-cloud-patches';

    /**
     * @param array<string, string> $env
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $env,
        private readonly string $cwd,
    ) {
        parent::__construct('rewind');
    }

    protected function configure(): void
    {
        $this->setDescription(
            'Reverse the patch queue in vendor/ so ece-patches apply can run again (needs the patch program).',
        )
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Store root (default: current directory).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filesystem = $this->container->filesystem();
        $given = $input->getOption('root');
        $root = (new ConfigResolver($filesystem))
            ->resolveRoot(is_string($given) ? $given : null, $this->env, $this->cwd);
        $this->container->patchBinary()->requireAvailable();

        $installation = (new InstallationReader($filesystem))->read($root);
        if (!$filesystem->isDirectory($root . '/' . self::CLOUD_PATCHES_DIRECTORY)) {
            throw new RuntimeFailure(
                'magento/magento-cloud-patches is not installed, so ece-patches apply could not reapply the patches'
                . ' after a rewind. Install it, or do not run patchline rewind.',
            );
        }

        $queue = (new QueueReader($filesystem))->read($installation);
        $dryRun = $input->getOption('dry-run') === true;
        $result = $this->container->queueRewinder()->rewind($installation, $queue, $dryRun);

        $output->write(
            (new RewindTextRenderer($this->style($output)))->render($result, $queue->warnings, $dryRun),
            false,
            OutputInterface::OUTPUT_RAW,
        );

        return $result->success ? 0 : 1;
    }

    private function style(OutputInterface $output): Style
    {
        $colour = $output->isDecorated() && ($this->env['NO_COLOR'] ?? '') === '';

        return $colour ? Style::enabled() : Style::disabled();
    }
}
