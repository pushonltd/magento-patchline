<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Detection;

use Patchline\Detection\DiffParser;
use Patchline\Detection\DiffSection;
use Patchline\Detection\FilteredDiff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiffParser::class)]
#[CoversClass(DiffSection::class)]
#[CoversClass(FilteredDiff::class)]
final class DiffParserTest extends TestCase
{
    private const NUAR = "diff -Nuar a/vendor/magento/module-a/A.php b/vendor/magento/module-a/A.php\n"
        . "--- a/vendor/magento/module-a/A.php\n+++ b/vendor/magento/module-a/A.php\n@@ -1 +1 @@\n-x\n+y\n"
        . "diff -Nuar a/vendor/magento/module-b/B.php b/vendor/magento/module-b/B.php\n"
        . "--- /dev/null\n+++ b/vendor/magento/module-b/B.php\n@@ -0,0 +1 @@\n+new\n"
        . "diff -Nuar a/vendor/magento/module-a/A.php b/vendor/magento/module-a/A.php\n"
        . "--- a/vendor/magento/module-a/A.php\n+++ b/vendor/magento/module-a/A.php\n@@ -5 +5 @@\n-p\n+q\n";

    private const GIT = "diff --git a/vendor/magento/module-a/A.php b/vendor/magento/module-a/A.php\n"
        . "index 111..222 100644\n--- a/vendor/magento/module-a/A.php\n+++ b/vendor/magento/module-a/A.php\n"
        . "@@ -1 +1 @@\n-x\n+y\n"
        . "diff --git a/app/etc/c.xml b/app/etc/c.xml\n"
        . "--- a/app/etc/c.xml\n+++ b/app/etc/c.xml\n@@ -1 +1 @@\n-x\n+y\n";

    private const PLAIN = "--- a/vendor/magento/module-a/A.php\t2026-01-01\n"
        . "+++ b/vendor/magento/module-a/A.php\t2026-01-02\n@@ -1 +1 @@\n-x\n+y\n"
        . "--- a/vendor/magento/module-b/B.php\n+++ b/vendor/magento/module-b/B.php\n@@ -1 +1 @@\n-x\n+y\n";

    private const MIXED = "diff -Nuar a/vendor/acme/replaced/R.php b/vendor/acme/replaced/R.php\n"
        . "--- a/vendor/acme/replaced/R.php\n+++ b/vendor/acme/replaced/R.php\n@@ -1 +1 @@\n-x\n+y\n"
        . "diff -Nuar a/vendor/magento/module-a/A.php b/vendor/magento/module-a/A.php\n"
        . "--- a/vendor/magento/module-a/A.php\n+++ b/vendor/magento/module-a/A.php\n@@ -1 +1 @@\n-x\n+y\n"
        . "diff -Nuar a/vendor/bin/patch-status b/vendor/bin/patch-status\n"
        . "--- a/vendor/bin/patch-status\n+++ b/vendor/bin/patch-status\n@@ -1 +1 @@\n-x\n+y\n";

    public function test_it_lists_files_touched_by_a_diff_with_diff_nuar_headers(): void
    {
        self::assertSame(
            ['vendor/magento/module-a/A.php', 'vendor/magento/module-b/B.php'],
            (new DiffParser())->files(self::NUAR),
        );
    }

    public function test_it_lists_files_touched_by_a_diff_with_diff_git_headers(): void
    {
        self::assertSame(
            ['vendor/magento/module-a/A.php', 'app/etc/c.xml'],
            (new DiffParser())->files(self::GIT),
        );
    }

    public function test_it_lists_files_touched_by_a_plain_unified_diff(): void
    {
        self::assertSame(
            ['vendor/magento/module-a/A.php', 'vendor/magento/module-b/B.php'],
            (new DiffParser())->files(self::PLAIN),
        );
    }

    public function test_it_splits_a_diff_into_sections_that_join_back_to_the_original_text(): void
    {
        $parser = new DiffParser();
        foreach ([self::NUAR, self::GIT, self::PLAIN, self::MIXED] as $diff) {
            $sections = $parser->sections($diff);
            self::assertGreaterThan(1, count($sections));
            self::assertSame($diff, implode('', array_map(static fn (DiffSection $s): string => $s->text, $sections)));
        }
        self::assertSame('app/etc/c.xml', $parser->sections(self::GIT)[1]->path);
    }

    public function test_it_drops_sections_for_replaced_packages_and_reports_them(): void
    {
        $result = (new DiffParser())->withoutPackages(self::MIXED, ['acme/replaced']);

        self::assertStringNotContainsString('acme/replaced', $result->text);
        self::assertStringContainsString('module-a', $result->text);
        self::assertSame(['acme/replaced'], $result->skippedPackages);
        self::assertSame([], $result->presentPackages);
        self::assertFalse($result->isEmpty());
    }

    public function test_it_keeps_a_replaced_package_section_whose_file_is_present_and_reports_it_as_present(): void
    {
        $result = (new DiffParser())->withoutPackages(
            self::MIXED,
            ['acme/replaced'],
            ['vendor/acme/replaced/R.php'],
        );

        self::assertStringContainsString('acme/replaced', $result->text);
        self::assertSame([], $result->skippedPackages);
        self::assertSame(['acme/replaced'], $result->presentPackages);
    }

    public function test_it_reports_a_diff_as_empty_when_every_section_targets_a_replaced_package(): void
    {
        $result = (new DiffParser())->withoutPackages(self::NUAR, ['magento/module-a', 'magento/module-b']);

        self::assertTrue($result->isEmpty());
        self::assertSame(['magento/module-a', 'magento/module-b'], $result->skippedPackages);
    }

    public function test_it_strips_the_vendor_bin_patch_status_section(): void
    {
        $result = (new DiffParser())->withoutCumulativeTool(self::MIXED);

        self::assertStringNotContainsString('patch-status', $result);
        self::assertStringContainsString('module-a', $result);
        self::assertStringContainsString('acme/replaced', $result);
    }

    public function test_it_lists_the_new_path_of_a_plain_diff_that_creates_a_file(): void
    {
        $diff = "--- /dev/null\n+++ b/app/etc/new.xml\n@@ -0,0 +1 @@\n+x\n";

        self::assertSame(['app/etc/new.xml'], (new DiffParser())->files($diff));
    }

    public function test_it_lists_the_old_path_of_a_plain_diff_that_deletes_a_file(): void
    {
        $diff = "--- a/app/etc/gone.xml\n+++ /dev/null\n@@ -1 +0,0 @@\n-x\n";

        self::assertSame(['app/etc/gone.xml'], (new DiffParser())->files($diff));
    }

    public function test_it_finds_no_sections_in_an_empty_diff(): void
    {
        self::assertSame([], (new DiffParser())->sections(''));
    }

    public function test_it_keeps_text_before_the_first_file_header_with_the_first_section(): void
    {
        $diff = "Subject: fix\n\n" . self::PLAIN;

        $sections = (new DiffParser())->sections($diff);

        self::assertCount(2, $sections);
        self::assertSame('vendor/magento/module-a/A.php', $sections[0]->path);
        self::assertStringStartsWith("Subject: fix\n\n--- a/", $sections[0]->text);
    }

    public function test_it_returns_text_without_any_file_header_as_one_section_without_a_path(): void
    {
        $sections = (new DiffParser())->sections("just some notes\n");

        self::assertCount(1, $sections);
        self::assertSame('', $sections[0]->path);
        self::assertSame("just some notes\n", $sections[0]->text);
    }

    public function test_it_splits_a_diff_nuar_diff_into_one_section_per_file_each_starting_at_its_diff_line(): void
    {
        $sections = (new DiffParser())->sections(self::NUAR);

        self::assertCount(3, $sections);
        foreach ($sections as $section) {
            self::assertStringStartsWith('diff -Nuar ', $section->text);
        }
        self::assertSame(
            ['vendor/magento/module-a/A.php', 'vendor/magento/module-b/B.php', 'vendor/magento/module-a/A.php'],
            array_map(static fn (DiffSection $section): string => $section->path, $sections),
        );
    }

    public function test_it_splits_plain_diffs_whose_hunks_have_an_even_number_of_lines(): void
    {
        $diff = "--- a/one.php\n+++ b/one.php\n@@ -1,2 +1,2 @@\n ctx\n-x\n+y\n"
            . "--- a/two.php\n+++ b/two.php\n@@ -1,2 +1,2 @@\n ctx\n-x\n+y\n";

        $sections = (new DiffParser())->sections($diff);

        self::assertSame(['one.php', 'two.php'], array_map(
            static fn (DiffSection $section): string => $section->path,
            $sections,
        ));
    }

    public function test_it_keeps_a_deleted_line_that_looks_like_a_file_header_inside_its_section(): void
    {
        $diff = "--- a/one.php\n+++ b/one.php\n@@ -1,3 +1,3 @@\n ctx\n--- sql comment\n ctx\n+new\n"
            . "@@ -9,3 +9,3 @@\n ctx\n ctx\n-gone\n+new\n";

        $sections = (new DiffParser())->sections($diff);

        self::assertCount(1, $sections);
        self::assertSame($diff, $sections[0]->text);
    }

    public function test_it_does_not_start_a_section_at_a_hunk_line_that_merely_mentions_diff(): void
    {
        $diff = "--- a/one.php\n+++ b/one.php\n@@ -1 +1,2 @@\n ctx\n+run diff -u a/x b/y first\n";

        $parser = new DiffParser();

        self::assertCount(1, $parser->sections($diff));
        self::assertSame(['one.php'], $parser->files($diff));
    }

    public function test_it_attaches_text_before_a_diff_line_to_the_first_file_section(): void
    {
        $diff = "From: someone\n" . self::NUAR;

        $sections = (new DiffParser())->sections($diff);

        self::assertCount(3, $sections);
        self::assertSame('vendor/magento/module-a/A.php', $sections[0]->path);
        self::assertStringStartsWith("From: someone\ndiff -Nuar ", $sections[0]->text);
        self::assertSame($diff, implode('', array_map(static fn (DiffSection $s): string => $s->text, $sections)));
    }

    public function test_it_keeps_every_chunk_without_a_file_before_the_first_file_section(): void
    {
        $diff = "diff -Nuar broken one\ndiff -Nuar broken two\n" . self::NUAR;

        $sections = (new DiffParser())->sections($diff);

        self::assertSame($diff, implode('', array_map(static fn (DiffSection $s): string => $s->text, $sections)));
        self::assertSame('vendor/magento/module-a/A.php', $sections[0]->path);
        self::assertStringStartsWith("diff -Nuar broken one\ndiff -Nuar broken two\ndiff -Nuar a/", $sections[0]->text);
    }

    public function test_it_lists_files_around_a_section_without_a_path_and_without_repeating_a_file(): void
    {
        $diff = "diff -Nuar a/one.php b/one.php\n--- a/one.php\n+++ b/one.php\n@@ -1 +1 @@\n-x\n+y\n"
            . "diff -Nuar broken\n"
            . "diff -Nuar a/one.php b/one.php\n--- a/one.php\n+++ b/one.php\n@@ -5 +5 @@\n-x\n+y\n"
            . "diff -Nuar a/two.php b/two.php\n--- a/two.php\n+++ b/two.php\n@@ -1 +1 @@\n-x\n+y\n";

        self::assertSame(['one.php', 'two.php'], (new DiffParser())->files($diff));
    }

    public function test_it_names_the_new_path_of_a_diff_line_and_does_not_need_file_headers_for_it(): void
    {
        $diff = "diff --git a/old/name.php b/new/name.php\nsimilarity index 100%\nrename from old/name.php\n";

        self::assertSame(['new/name.php'], (new DiffParser())->files($diff));
    }

    public function test_it_ignores_a_diff_line_quoted_inside_a_plain_diff_when_naming_the_file(): void
    {
        $diff = "--- a/real.php\n+++ b/real.php\n@@ -1 +1,2 @@\n ctx\n+see diff -u a/old b/new\n";

        self::assertSame(['real.php'], (new DiffParser())->files($diff));
    }

    public function test_it_names_a_deleted_file_without_a_directory_by_its_old_name(): void
    {
        $diff = "--- notes\n+++ /dev/null\n@@ -1 +0,0 @@\n-x\n";

        self::assertSame(['notes'], (new DiffParser())->files($diff));
    }

    public function test_it_keeps_every_section_around_a_dropped_replaced_package_section(): void
    {
        $result = (new DiffParser())->withoutPackages(self::MIXED, ['acme/replaced']);

        $sections = (new DiffParser())->sections(self::MIXED);
        self::assertSame($sections[1]->text . $sections[2]->text, $result->text);
        self::assertSame(['acme/replaced'], $result->skippedPackages);
    }

    public function test_it_keeps_the_text_before_and_after_a_replaced_package_section_whose_file_is_present(): void
    {
        $diff = self::NUAR . self::MIXED;

        $result = (new DiffParser())->withoutPackages(
            $diff,
            ['acme/replaced'],
            ['vendor/acme/replaced/R.php'],
        );

        self::assertSame($diff, $result->text);
        self::assertSame(['acme/replaced'], $result->presentPackages);
        self::assertSame([], $result->skippedPackages);
    }

    public function test_it_does_not_mistake_a_package_whose_name_only_starts_the_same_for_a_replaced_one(): void
    {
        $diff = "diff -Nuar a/vendor/magento/module-ab/X.php b/vendor/magento/module-ab/X.php\n"
            . "--- a/vendor/magento/module-ab/X.php\n+++ b/vendor/magento/module-ab/X.php\n@@ -1 +1 @@\n-x\n+y\n";

        $result = (new DiffParser())->withoutPackages($diff, ['magento/module-a']);

        self::assertSame($diff, $result->text);
        self::assertSame([], $result->skippedPackages);
    }

    public function test_it_keeps_the_sections_after_the_patch_status_section(): void
    {
        $parser = new DiffParser();
        $diff = $this->section('vendor/bin/patch-status') . $this->section('a.php') . $this->section('b.php');

        self::assertSame($this->section('a.php') . $this->section('b.php'), $parser->withoutCumulativeTool($diff));
    }

    private function section(string $path): string
    {
        return "diff -Nuar a/$path b/$path\n--- a/$path\n+++ b/$path\n@@ -1 +1 @@\n-x\n+y\n";
    }
}
