<?php

declare(strict_types=1);

namespace Patchline\Cli\Console;

use Symfony\Component\Console\Application;

/**
 * The Symfony Console application, configured the way patchline runs it: exceptions are mapped to exit codes
 * by the caller, and --version prints "patchline <version>". The command list starts with the banner.
 */
final class ConsoleApplication extends Application
{
    private const BANNER = <<<'TXT'
        pushonltd/
                     _       _     _ _
         _ __   __ _| |_ ___| |__ | (_)_ __   ___
        | '_ \ / _` | __/ __| '_ \| | | '_ \ / _ \
        | |_) | (_| | || (__| | | | | | | | |  __/
        | .__/ \__,_|\__\___|_| |_|_|_|_| |_|\___|
        |_|
        TXT;

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

    public function getHelp(): string
    {
        return self::BANNER . "\n\n" . $this->getLongVersion();
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
