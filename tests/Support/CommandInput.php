<?php

declare(strict_types=1);

namespace Patchline\Tests\Support;

use Patchline\Cli\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Parses command line arguments against the input definition of the patchline console command they name.
 */
final class CommandInput
{
    /**
     * @param list<string> $argv arguments without the script name, starting with the command
     */
    public static function parse(array $argv): InputInterface
    {
        $input = new ArgvInput(['patchline', ...$argv]);
        $command = (new Application())->console([], '/')->find((string) $input->getFirstArgument());
        $command->mergeApplicationDefinition();
        $input->bind($command->getDefinition());

        return $input;
    }
}
