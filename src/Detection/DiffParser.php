<?php

declare(strict_types=1);

namespace Patchline\Detection;

final class DiffParser
{
    private const CUMULATIVE_TOOL = 'vendor/bin/patch-status';

    /**
     * @return list<string>
     */
    public function files(string $diff): array
    {
        $paths = [];
        foreach ($this->sections($diff) as $section) {
            if ($section->path === '' || in_array($section->path, $paths, true)) {
                continue;
            }

            $paths[] = $section->path;
        }

        return $paths;
    }

    /**
     * @return list<DiffSection>
     */
    public function sections(string $diff): array
    {
        preg_match_all('/[^\n]*\n|[^\n]+/', $diff, $matches);
        $lines = $matches[0];

        /** @var list<list<string>> $groups */
        $groups = [];
        $current = [];
        $seenHunk = false;
        foreach ($lines as $i => $line) {
            if ($this->startsSection($lines, $i, $current !== [], $seenHunk)) {
                $groups[] = $current;
                $current = [];
                $seenHunk = false;
            }
            $current[] = $line;
            $seenHunk = $seenHunk || str_starts_with($line, '@@');
        }
        $groups[] = $current;

        $sections = [];
        $preamble = '';
        foreach ($groups as $group) {
            if ($group === []) {
                continue;
            }
            $text = implode('', $group);
            $path = $this->pathOf($group);
            if ($path === '' && $sections === []) {
                $preamble .= $text;
                continue;
            }
            $sections[] = new DiffSection($path, $preamble . $text);
            $preamble = '';
        }
        if ($preamble !== '') {
            $sections[] = new DiffSection('', $preamble);
        }

        return $sections;
    }

    /**
     * @param list<string> $packageNames
     * @param list<string> $presentPaths
     */
    public function withoutPackages(string $diff, array $packageNames, array $presentPaths = []): FilteredDiff
    {
        $text = '';
        $skipped = [];
        $present = [];
        foreach ($this->sections($diff) as $section) {
            $package = $this->packageOf($section->path, $packageNames);
            if ($package === null) {
                $text .= $section->text;
                continue;
            }
            if (in_array($section->path, $presentPaths, true)) {
                $text .= $section->text;
                $present[$package] = $package;
                continue;
            }
            $skipped[$package] = $package;
        }

        return new FilteredDiff($text, array_values($skipped), array_values($present));
    }

    public function withoutCumulativeTool(string $diff): string
    {
        $text = '';
        foreach ($this->sections($diff) as $section) {
            if ($section->path === self::CUMULATIVE_TOOL) {
                continue;
            }

            $text .= $section->text;
        }

        return $text;
    }

    /**
     * @param list<string> $lines
     */
    private function startsSection(array $lines, int $index, bool $hasContent, bool $seenHunk): bool
    {
        $line = $lines[$index];
        if (preg_match('/^diff (?:-\S+|--git) /', $line) === 1) {
            return $hasContent;
        }
        $isPlainHeader = str_starts_with($line, '--- ')
            && str_starts_with($lines[$index + 1] ?? '', '+++ ')
            && str_starts_with($lines[$index + 2] ?? '', '@@');

        return $isPlainHeader && $hasContent && $seenHunk;
    }

    /**
     * @param list<string> $lines
     */
    private function pathOf(array $lines): string
    {
        $old = '';
        $new = '';
        foreach ($lines as $line) {
            if (preg_match('/^diff (?:-\S+|--git) a\/(.+?) b\/(.+?)\r?\n?$/', $line, $m) === 1) {
                return $m[2];
            }
            if (str_starts_with($line, '--- ') && $old === '') {
                $old = $this->stripPrefix(substr($line, 4));
            } elseif (str_starts_with($line, '+++ ') && $new === '') {
                $new = $this->stripPrefix(substr($line, 4));
            }
        }

        return $new !== '' ? $new : $old;
    }

    private function stripPrefix(string $header): string
    {
        $path = rtrim(explode("\t", $header)[0], "\r\n");
        if ($path === '/dev/null') {
            return '';
        }
        $slash = strpos($path, '/');

        return $slash === false ? $path : substr($path, $slash + 1);
    }

    /**
     * @param list<string> $packageNames
     */
    private function packageOf(string $path, array $packageNames): ?string
    {
        foreach ($packageNames as $name) {
            if (str_starts_with($path, 'vendor/' . $name . '/')) {
                return $name;
            }
        }

        return null;
    }
}
