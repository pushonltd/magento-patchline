<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Tools;

use Patchline\Tests\Support\PhpProcess;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CoverageCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/patchline-cov-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function test_it_fails_the_coverage_check_below_the_threshold(): void
    {
        $clover = $this->clover(94, 100);

        $result = PhpProcess::run([$this->script(), $clover, '95']);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('94.00%', $result['stderr']);
    }

    public function test_it_passes_the_coverage_check_at_or_above_the_threshold(): void
    {
        $clover = $this->clover(95, 100);

        $result = PhpProcess::run([$this->script(), $clover, '95']);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('95.00%', $result['stdout']);
    }

    private function script(): string
    {
        return dirname(__DIR__, 3) . '/tools/coverage-check.php';
    }

    private function clover(int $covered, int $total): string
    {
        $path = $this->dir . '/clover.xml';
        file_put_contents($path, sprintf(
            '<?xml version="1.0"?><coverage><project>'
            . '<metrics statements="%d" coveredstatements="%d"/></project></coverage>',
            $total,
            $covered,
        ));

        return $path;
    }
}
