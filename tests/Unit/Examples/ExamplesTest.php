<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Examples;

use Patchline\Tests\Support\CommandInput;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ExamplesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function test_the_github_example_passes_zizmor_with_the_pedantic_persona(): void
    {
        $zizmor = trim((string) shell_exec('command -v zizmor 2>/dev/null'));
        if ($zizmor === '') {
            self::markTestSkipped('zizmor is not installed; the CI zizmor job covers examples/.');
        }

        $output = [];
        exec(
            sprintf(
                '%s --persona=pedantic --offline --no-progress %s 2>&1',
                escapeshellarg($zizmor),
                escapeshellarg($this->path('examples/github/patchline-sync.yml')),
            ),
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_the_github_example_pins_every_action_to_a_full_commit_sha(): void
    {
        $workflow = $this->read('examples/github/patchline-sync.yml');

        preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/m', $workflow, $matches, PREG_SET_ORDER);

        self::assertNotEmpty($matches);
        foreach ($matches as $match) {
            self::assertMatchesRegularExpression('/@[0-9a-f]{40}$/', $match[1], $match[1] . ' is not pinned');
            self::assertMatchesRegularExpression('/^\s*#\s*v?\d+(\.\d+)*/', $match[2], $match[1] . ' has no version');
        }
    }

    public function test_the_github_example_does_not_use_the_either_day_cron_trap(): void
    {
        $workflow = $this->read('examples/github/patchline-sync.yml');

        preg_match_all('/^\s*-\s*cron:\s*"([^"]+)"/m', $workflow, $matches);

        self::assertNotEmpty($matches[1]);
        foreach ($matches[1] as $cron) {
            $fields = explode(' ', $cron);
            self::assertCount(5, $fields);
            $restrictsBoth = $fields[2] !== '*' && $fields[4] !== '*';
            self::assertFalse($restrictsBoth, $cron . ' restricts day-of-month and day-of-week, which cron ORs');
        }
    }

    public function test_the_gitlab_example_publishes_the_junit_report_as_an_artifact(): void
    {
        $ci = $this->read('examples/gitlab/.gitlab-ci.patchline.yml');

        self::assertStringContainsString('patchline status --format=junit > patchline.xml', $ci);
        self::assertMatchesRegularExpression('/artifacts:\n(?:\s+.*\n)*?\s+reports:\n\s+junit: patchline\.xml/', $ci);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function exampleFiles(): array
    {
        return [
            'github' => ['examples/github/patchline-sync.yml'],
            'gitlab' => ['examples/gitlab/.gitlab-ci.patchline.yml'],
            'composer' => ['examples/composer-scripts.json'],
            'docs' => ['docs/ci.md'],
        ];
    }

    #[DataProvider('exampleFiles')]
    public function test_every_command_in_the_examples_is_a_valid_patchline_invocation(string $file): void
    {
        $invocations = $this->invocations($this->read($file));

        self::assertNotEmpty($invocations, $file . ' contains no patchline command');
        foreach ($invocations as $arguments) {
            $input = CommandInput::parse($arguments);
            self::assertContains($input->getFirstArgument(), ['status', 'sync', 'verify'], implode(' ', $arguments));
        }
    }

    /**
     * @return list<list<string>>
     */
    private function invocations(string $contents): array
    {
        $found = [];
        foreach (explode("\n", $contents) as $line) {
            $pattern = '/(?<![\w-])patchline\s+((?:status|sync|verify)\b[^`"|>]*)/';
            if (preg_match($pattern, $line, $match) !== 1) {
                continue;
            }
            $found[] = explode(' ', trim($match[1]));
        }

        return $found;
    }

    private function path(string $relative): string
    {
        return self::ROOT . '/' . $relative;
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents($this->path($relative));
        self::assertIsString($contents);

        return $contents;
    }
}
