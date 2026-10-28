<?php

declare(strict_types=1);

namespace Patchline\Cli\Console;

use Symfony\Component\Console\Application;

/**
 * The Symfony Console application, configured the way patchline runs it: exceptions are mapped to exit codes
 * by the caller, and --version prints "patchline <version>".
 */
final class ConsoleApplication extends Application
{
    /**
     * @param list<\Symfony\Component\Console\Command\Command> $commands
     */
    public function __construct(private readonly string $packageVersion, private readonly array $commands)
    {
        parent::__construct('patchline', $packageVersion);
        $this->setAutoExit(false);
        $this->setCatchExceptions(false);
    }

    public function getLongVersion(): string
    {
        return 'patchline ' . $this->packageVersion;
    }

    /**
     * Registered here because Application::add() was renamed in Console 7.4 and addCommand() does not exist before 7.4.
     *
     * @return array<\Symfony\Component\Console\Command\Command>
     */
    protected function getDefaultCommands(): array
    {
        return [...parent::getDefaultCommands(), ...$this->commands];
    }
}
