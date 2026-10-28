<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReplacedClassesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function removedClasses(): iterable
    {
        yield 'argv parser' => ['Cli/ArgvParser', 'Patchline\Cli\ArgvParser'];
        yield 'parsed args' => ['Cli/ParsedArgs', 'Patchline\Cli\ParsedArgs'];
        yield 'help text' => ['Cli/HelpText', 'Patchline\Cli\HelpText'];
        yield 'stream http client' => ['Infrastructure/StreamHttpClient', 'Patchline\Infrastructure\StreamHttpClient'];
        yield 'proc open runner' => [
            'Infrastructure/ProcOpenProcessRunner',
            'Patchline\Infrastructure\ProcOpenProcessRunner',
        ];
    }

    #[DataProvider('removedClasses')]
    public function test_it_no_longer_ships_the_hand_written_argv_parser_stream_http_client_or_proc_open_runner(
        string $file,
        string $class,
    ): void {
        self::assertFileDoesNotExist(__DIR__ . '/../../../src/' . $file . '.php');
        self::assertFalse(class_exists($class));
    }
}
