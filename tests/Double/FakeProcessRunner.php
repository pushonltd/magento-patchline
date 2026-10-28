<?php

declare(strict_types=1);

namespace Patchline\Tests\Double;

use LogicException;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Infrastructure\ProcessRunner;

final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, stdin: ?string, cwd: ?string}> */
    public array $calls = [];

    /** @var list<ProcessResult|callable(list<string>, ?string, ?string): ProcessResult> */
    private array $queue = [];

    /**
     * @param ProcessResult|callable(list<string>, ?string, ?string): ProcessResult $result
     */
    public function enqueue(ProcessResult|callable $result): self
    {
        $this->queue[] = $result;

        return $this;
    }

    public function run(array $command, ?string $stdin = null, ?string $cwd = null): ProcessResult
    {
        $this->calls[] = ['command' => $command, 'stdin' => $stdin, 'cwd' => $cwd];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new LogicException('FakeProcessRunner has no result queued for: ' . implode(' ', $command));
        }

        return $next instanceof ProcessResult ? $next : $next($command, $stdin, $cwd);
    }
}
