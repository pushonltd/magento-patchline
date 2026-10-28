<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Infrastructure;

use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Infrastructure\SymfonyProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
final class SymfonyProcessRunnerTest extends TestCase
{
    public function test_it_runs_a_process_from_an_argument_list_and_captures_exit_code_stdout_and_stderr(): void
    {
        $code = 'fwrite(STDOUT, "out:" . $argv[1]); fwrite(STDERR, "err"); exit(3);';

        $result = (new SymfonyProcessRunner())->run([PHP_BINARY, '-r', $code, 'a b; $x']);

        self::assertSame(3, $result->exitCode);
        self::assertSame('out:a b; $x', $result->stdout);
        self::assertSame('err', $result->stderr);
    }

    public function test_it_passes_stdin_to_the_process(): void
    {
        $result = (new SymfonyProcessRunner())->run([PHP_BINARY, '-r', 'echo stream_get_contents(STDIN);'], 'input');

        self::assertSame('input', $result->stdout);
    }

    public function test_it_captures_large_stdout_and_stderr_output_without_deadlocking(): void
    {
        $code = '$c = str_repeat("x", 1024); for ($i = 0; $i < 1024; $i++) { fwrite(STDOUT, $c); fwrite(STDERR, $c); }';

        $result = (new SymfonyProcessRunner())->run([PHP_BINARY, '-r', $code]);

        self::assertSame(0, $result->exitCode);
        self::assertSame(1024 * 1024, strlen($result->stdout));
        self::assertSame(1024 * 1024, strlen($result->stderr));
    }

    public function test_it_throws_a_runtime_failure_when_the_process_cannot_be_started(): void
    {
        $this->expectException(RuntimeFailure::class);
        $this->expectExceptionMessage(PHP_BINARY);

        (new SymfonyProcessRunner())->run([PHP_BINARY, '-v'], null, '/nonexistent/dir');
    }
}
