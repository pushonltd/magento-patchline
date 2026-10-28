<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\CrossCheck;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\Report;
use Patchline\CrossCheck\CrossCheckResult;
use Patchline\CrossCheck\Disagreement;
use Patchline\CrossCheck\PatchStatusCrossCheck;
use Patchline\Detection\DetectionResult;
use Patchline\Infrastructure\LocalFilesystem;
use Patchline\Infrastructure\ProcessResult;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\RegistryPatch;
use Patchline\Tests\Double\FakeProcessRunner;
use Patchline\Tests\Double\TempDirectory;
use Patchline\Tests\Support\ReportFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PatchStatusCrossCheck::class)]
#[CoversClass(CrossCheckResult::class)]
#[CoversClass(Disagreement::class)]
#[UsesClass(PatchReport::class)]
#[UsesClass(Report::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(LocalFilesystem::class)]
#[UsesClass(ProcessResult::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(RegistryPatch::class)]
final class PatchStatusCrossCheckTest extends TestCase
{
    private TempDirectory $store;
    private FakeProcessRunner $processes;

    protected function setUp(): void
    {
        $this->store = TempDirectory::create();
        $this->processes = new FakeProcessRunner();
    }

    protected function tearDown(): void
    {
        $this->store->remove();
    }

    public function test_it_reports_agreement_when_both_tools_detect_the_same_applied_patches(): void
    {
        $this->installPatchStatus();
        $this->processes->enqueue($this->adobe(
            ['249-2026-09-001-CE'],
            ['249-2026-10-001-CE', '249-VULN-39341-CE'],
        ));

        $result = $this->check(false);

        self::assertTrue($result->ran());
        self::assertTrue($result->agrees());
        self::assertSame(3, $result->compared);
        self::assertSame([], $result->disagreements);
        self::assertSame(
            [[
                PHP_BINARY,
                $this->store->path('vendor/bin/patch-status'),
                '--root=' . $this->store->path(),
                '--format=json',
            ]],
            array_column($this->processes->calls, 'command'),
        );
    }

    public function test_it_passes_no_cache_through_to_patch_status(): void
    {
        $this->installPatchStatus();
        $this->processes->enqueue($this->adobe(['249-2026-09-001-CE'], ['249-2026-10-001-CE', '249-VULN-39341-CE']));

        $this->check(true);

        self::assertContains('--no-cache', $this->processes->calls[0]['command']);
    }

    public function test_it_reports_each_patch_the_two_tools_disagree_on(): void
    {
        $this->installPatchStatus();
        // Adobe: A unknown, B applied, C absent from every list. Patchline: A applied, B and C not applied.
        $this->processes->enqueue($this->adobe(['249-2026-10-001-CE'], [], ['249-2026-09-001-CE']));

        $result = $this->check(false);

        self::assertFalse($result->agrees());
        self::assertEquals(
            [
                new Disagreement('249-2026-09-001-CE', 'applied', 'unknown'),
                new Disagreement('249-2026-10-001-CE', 'not_applied', 'applied'),
                new Disagreement('249-VULN-39341-CE', 'not_applied', 'not_listed'),
            ],
            $result->disagreements,
        );
    }

    public function test_it_reports_a_disagreement_for_patches_only_adobe_lists(): void
    {
        $this->installPatchStatus();
        $this->processes->enqueue($this->adobe(
            ['249-2026-09-001-CE', '249-OTHER-CE'],
            ['249-2026-10-001-CE', '249-VULN-39341-CE'],
        ));

        $result = $this->check(false);

        self::assertEquals([new Disagreement('249-OTHER-CE', 'not_applicable', 'applied')], $result->disagreements);
    }

    public function test_it_skips_with_a_warning_when_patch_status_is_not_installed(): void
    {
        $result = $this->check(false);

        self::assertFalse($result->ran());
        self::assertFalse($result->agrees());
        self::assertSame(
            'Adobe patch-status not installed on this store; cross-check skipped',
            $result->warning,
        );
        self::assertSame([], $this->processes->calls);
    }

    public function test_it_skips_with_a_warning_when_patch_status_output_is_not_valid_json(): void
    {
        $this->installPatchStatus();
        $this->processes->enqueue(new ProcessResult(0, 'not json', ''));

        $result = $this->check(false);

        self::assertFalse($result->ran());
        self::assertStringContainsString('output was not valid JSON', (string) $result->warning);
        self::assertStringContainsString('cross-check skipped', (string) $result->warning);
    }

    public function test_it_skips_with_a_warning_that_includes_stderr_when_patch_status_fails(): void
    {
        $this->installPatchStatus();
        $this->processes->enqueue(new ProcessResult(1, '', "Cannot load registry\nsecond line"));

        $result = $this->check(false);

        self::assertFalse($result->ran());
        self::assertStringContainsString('exited with status 1', (string) $result->warning);
        self::assertStringContainsString('Cannot load registry', (string) $result->warning);
    }

    public function test_it_skips_when_the_json_lacks_the_patch_lists(): void
    {
        $this->installPatchStatus();
        $this->processes->enqueue(new ProcessResult(0, '{"applied_patches": []}', ''));

        $result = $this->check(false);

        self::assertFalse($result->ran());
        self::assertStringContainsString('not valid JSON', (string) $result->warning);
    }

    private function check(bool $noCache): CrossCheckResult
    {
        return (new PatchStatusCrossCheck($this->processes, new LocalFilesystem()))
            ->check(ReportFixtures::mixed(), $this->store->path(), $noCache);
    }

    private function installPatchStatus(): void
    {
        mkdir($this->store->path('vendor/bin'), 0777, true);
        file_put_contents($this->store->path('vendor/bin/patch-status'), '<?php');
    }

    /**
     * @param list<string> $applied
     * @param list<string> $missing
     * @param list<string> $unknown
     */
    private function adobe(array $applied, array $missing, array $unknown = []): ProcessResult
    {
        $json = json_encode([
            'base_version' => '2.4.9',
            'applied_patches' => $applied,
            'missing_patches' => $missing,
            'unknown_patches' => $unknown,
            'warnings' => [],
        ], JSON_THROW_ON_ERROR);

        return new ProcessResult(0, $json, '');
    }
}
