<?php

declare(strict_types=1);

namespace Patchline\Cli\Console;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Console output over caller-supplied streams (Symfony's ConsoleOutput always opens php://stdout and
 * php://stderr), so the application can be run in-process with captured streams.
 */
final class StreamConsoleOutput extends StreamOutput implements ConsoleOutputInterface
{
    /** @var array<ConsoleSectionOutput> */
    private array $sections = [];

    private OutputInterface $error;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct($stdout, $stderr, bool $decorated)
    {
        parent::__construct($stdout, self::VERBOSITY_NORMAL, $decorated);
        $this->error = new StreamOutput($stderr, self::VERBOSITY_NORMAL, $decorated, $this->getFormatter());
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->error;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->error = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        return new ConsoleSectionOutput(
            $this->getStream(),
            $this->sections,
            $this->getVerbosity(),
            $this->isDecorated(),
            $this->getFormatter(),
        );
    }
}
