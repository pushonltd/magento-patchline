<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Docs;

use Patchline\Analysis\PatchState;
use Patchline\Cli\Application;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ReadmeTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function test_every_command_and_flag_in_the_readme_exists_in_patchline_help(): void
    {
        $commands = (new Application())->console([], '/')->all();
        $readme = $this->readme();

        $documented = [];
        preg_match_all('/^patchline (\w[\w-]*)/m', $readme, $used);
        foreach ($used[1] as $name) {
            self::assertArrayHasKey($name, $commands, sprintf('README uses unknown command "%s".', $name));
        }

        $section = $this->section($readme, 'Commands');
        foreach (['status', 'sync', 'verify', 'rewind'] as $name) {
            self::assertArrayHasKey($name, $commands);
            self::assertStringContainsString('### `' . $name . '`', $section, $name . ' is not described.');
        }

        preg_match_all('/^\| `(--[a-z-]+)(?:=[^`]*)?` \|/m', $section, $flags);
        $known = [];
        foreach (['status', 'sync', 'verify', 'rewind'] as $name) {
            $command = $commands[$name];
            foreach ($command->getNativeDefinition()->getOptions() as $option) {
                $known[$option->getName()] = true;
            }
        }
        foreach ($flags[1] as $flag) {
            $documented[substr($flag, 2)] = true;
            self::assertArrayHasKey(substr($flag, 2), $known, sprintf('README documents unknown flag %s.', $flag));
        }
        foreach (array_keys($known) as $option) {
            self::assertArrayHasKey($option, $documented, sprintf('README does not document --%s.', $option));
        }
    }

    public function test_every_state_in_the_readme_states_table_exists_in_the_patch_state_enum(): void
    {
        $section = $this->section($this->readme(), 'Patch states');

        preg_match_all('/^\| `([a-z_]+)` \|/m', $section, $rows);

        $enum = array_map(static fn (PatchState $state): string => $state->value, PatchState::cases());
        self::assertEqualsCanonicalizing($enum, $rows[1]);
    }

    public function test_the_exit_codes_in_the_readme_match_the_application(): void
    {
        $section = $this->section($this->readme(), 'Exit codes');
        preg_match_all('/^\| (\d+) \|/m', $section, $rows);

        $source = (string) file_get_contents(self::ROOT . '/src/Cli/Application.php');
        preg_match('/returns the exit code: (.+)\./', $source, $doc);
        preg_match_all('/(\d) [a-z ]+/', $doc[1] ?? '', $codes);
        preg_match_all('/return (\d);/', $source, $returned);

        self::assertSame(['0', '1', '2', '3'], $rows[1]);
        self::assertEqualsCanonicalizing(['2', '3'], array_values(array_unique($returned[1])));
        self::assertSame($rows[1], $codes[1]);
    }

    public function test_the_recommended_wiring_runs_rewind_before_ece_patches_apply(): void
    {
        $section = $this->section($this->readme(), 'Recommended wiring');

        preg_match('/```json\n(.*?)```/s', $section, $block);
        $scripts = json_decode('{' . ($block[1] ?? '') . '}', true);

        self::assertIsArray($scripts);
        self::assertIsArray($scripts['scripts']);
        self::assertSame(
            ['php patchline.phar rewind', 'vendor/bin/ece-patches apply'],
            $scripts['scripts']['apply-patches'],
        );
    }

    private function readme(): string
    {
        $path = self::ROOT . '/README.md';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function section(string $readme, string $heading): string
    {
        $parts = explode("\n## ", $readme);
        foreach ($parts as $part) {
            if (str_starts_with($part, $heading . "\n")) {
                return $part;
            }
        }
        self::fail(sprintf('README has no "## %s" section.', $heading));
    }
}
