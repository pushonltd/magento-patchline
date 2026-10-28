<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Support;

use Patchline\Tests\Support\PhpProcess;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PhpProcessTest extends TestCase
{
    private const PRINT_ENV = 'foreach (["GITHUB_ACTIONS", "GITHUB_STEP_SUMMARY", "NO_COLOR", "PATCHLINE_FORMAT",'
        . ' "COMPOSER_AUTH", "KEEP_ME"] as $n) { echo $n, "=", var_export(getenv($n), true), "\n"; }';

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['GITHUB_ACTIONS', 'GITHUB_STEP_SUMMARY', 'NO_COLOR', 'PATCHLINE_FORMAT', 'COMPOSER_AUTH'] as $name) {
            $this->saved[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function test_it_does_not_leak_ci_and_patchline_environment_variables_into_child_processes(): void
    {
        putenv('GITHUB_ACTIONS=true');
        putenv('GITHUB_STEP_SUMMARY=/nowhere/summary.md');
        putenv('NO_COLOR=1');
        putenv('PATCHLINE_FORMAT=json');
        putenv('COMPOSER_AUTH={}');

        $result = PhpProcess::run(['-r', self::PRINT_ENV]);

        self::assertSame(
            "GITHUB_ACTIONS=false\nGITHUB_STEP_SUMMARY=false\nNO_COLOR=false\nPATCHLINE_FORMAT=false\n"
                . "COMPOSER_AUTH=false\nKEEP_ME=false\n",
            $result['stdout'],
        );
    }

    public function test_it_passes_explicitly_given_environment_variables(): void
    {
        putenv('GITHUB_ACTIONS=true');

        $result = PhpProcess::run(['-r', self::PRINT_ENV], null, ['GITHUB_ACTIONS' => 'true', 'KEEP_ME' => 'yes']);

        self::assertStringContainsString("GITHUB_ACTIONS='true'\n", $result['stdout']);
        self::assertStringContainsString("KEEP_ME='yes'\n", $result['stdout']);
    }
}
