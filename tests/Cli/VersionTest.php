<?php

declare(strict_types=1);

namespace Patchline\Tests\Cli;

use Patchline\PackageVersion;
use Patchline\Tests\Support\PhpProcess;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class VersionTest extends TestCase
{
    public function test_it_prints_the_version_and_exits_0_for_version_flag(): void
    {
        $result = PhpProcess::run([$this->bin(), '--version']);

        self::assertSame(0, $result['exitCode']);
        self::assertMatchesRegularExpression('/^patchline \S+\n$/', $result['stdout']);
        $expected = self::usesBuiltBinary() ? $result['stdout'] : 'patchline ' . PackageVersion::current() . "\n";
        self::assertSame($expected, $result['stdout']);
    }

    private function bin(): string
    {
        $override = getenv('PATCHLINE_BIN');

        return $override !== false && $override !== '' ? $override : dirname(__DIR__, 2) . '/bin/patchline';
    }

    private static function usesBuiltBinary(): bool
    {
        $override = getenv('PATCHLINE_BIN');

        return $override !== false && $override !== '';
    }
}
