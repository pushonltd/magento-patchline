<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\Report;
use Patchline\Analysis\ReportSummary;
use Patchline\Cli\Output\FormattedOutput;
use Patchline\Cli\Output\JsonFormatter;
use Patchline\Detection\DetectionResult;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\RegistryPatch;
use Patchline\Sync\Action\Download;
use Patchline\Sync\ActionOutcome;
use Patchline\Sync\ActionStatus;
use Patchline\Sync\ExecutionResult;
use Patchline\Tests\Support\Golden;
use Patchline\Tests\Support\ReportFixtures;
use Patchline\Verify\ReplayOutcome;
use Patchline\Verify\ReplayStep;
use Patchline\Verify\VerifyResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonFormatter::class)]
#[UsesClass(PatchReport::class)]
#[UsesClass(Report::class)]
#[UsesClass(ReportSummary::class)]
#[UsesClass(FormattedOutput::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(RegistryPatch::class)]
#[UsesClass(ActionOutcome::class)]
#[UsesClass(Download::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(ReplayStep::class)]
#[UsesClass(VerifyResult::class)]
final class JsonFormatterTest extends TestCase
{
    public function test_it_renders_json_with_the_schema_identifier_and_patch_status_compatible_keys(): void
    {
        $out = (new JsonFormatter())->format(ReportFixtures::mixed())->document;

        self::assertSame(Golden::expected('json-mixed.json', $out), $out);
        $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('patchline/report@1', $data['schema']);
        self::assertSame(['249-2026-09-001-CE'], $data['applied_patches']);
        self::assertSame(['249-2026-10-001-CE', '249-VULN-39341-CE'], $data['missing_patches']);
        self::assertSame([], $data['unknown_patches']);
        self::assertSame('remote', $data['registry_source']);
        self::assertArrayNotHasKey('sync', $data);
        self::assertArrayNotHasKey('verify', $data);
    }

    public function test_it_renders_cve_status_in_json(): void
    {
        $out = (new JsonFormatter())->format(ReportFixtures::mixed())->document;

        $data = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame(
            [
                'CVE-2026-0001' => ['status' => 'PROTECTED', 'patches' => ['249-2026-09-001-CE']],
                'CVE-2026-0002' => ['status' => 'VULNERABLE', 'patches' => ['249-2026-10-001-CE']],
                'CVE-2026-0003' => ['status' => 'VULNERABLE', 'patches' => ['249-2026-10-001-CE']],
                'CVE-2026-0004' => ['status' => 'VULNERABLE', 'patches' => ['249-VULN-39341-CE']],
            ],
            $data['vulnerability_status'],
        );
    }

    public function test_it_renders_identical_json_for_identical_reports(): void
    {
        $first = (new JsonFormatter())->format(ReportFixtures::mixed())->document;
        $second = (new JsonFormatter())->format(ReportFixtures::mixed())->document;

        self::assertSame($first, $second);
    }

    public function test_it_renders_empty_maps_as_objects(): void
    {
        $out = (new JsonFormatter())->format(ReportFixtures::empty())->document;

        self::assertStringContainsString('"installed_components": {}', $out);
        self::assertStringContainsString('"vulnerability_status": {}', $out);
        self::assertStringContainsString('"patches": []', $out);
    }

    public function test_it_renders_the_sync_block_when_sync_ran(): void
    {
        $patch = ReportFixtures::patch('249-2026-10-001-CE', '2026-10-13');
        $download = new Download($patch, 'm2-hotfixes/x.patch');
        $sync = new ExecutionResult([new ActionOutcome($download, ActionStatus::Planned, 'Would download.')]);

        $out = (new JsonFormatter())->format(ReportFixtures::clean(), $sync, null, true)->document;

        self::assertSame(Golden::expected('json-sync.json', $out), $out);
    }

    public function test_it_renders_the_verify_block_when_verify_ran(): void
    {
        $entry = ReportFixtures::entry(1, 'm2-hotfixes/bad.patch');
        $verify = new VerifyResult([new ReplayStep($entry, ReplayOutcome::Failed, 'Hunk #1 FAILED')], ['A note.']);

        $out = (new JsonFormatter())->format(ReportFixtures::clean(), null, $verify)->document;

        self::assertSame(Golden::expected('json-verify.json', $out), $out);
    }
}
