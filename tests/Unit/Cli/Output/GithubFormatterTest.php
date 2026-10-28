<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\Analysis\ReportSummary;
use Patchline\Cli\Output\FormattedOutput;
use Patchline\Cli\Output\GithubFormatter;
use Patchline\Detection\DetectionResult;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\RegistryPatch;
use Patchline\Sync\Action\Download;
use Patchline\Sync\Action\Warn;
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

#[CoversClass(GithubFormatter::class)]
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
#[UsesClass(Warn::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(ReplayStep::class)]
#[UsesClass(VerifyResult::class)]
final class GithubFormatterTest extends TestCase
{
    public function test_it_emits_an_error_annotation_for_every_failing_patch(): void
    {
        $report = StateReports::of([
            '249-A-CE' => PatchState::Missing,
            '249-B-CE' => PatchState::Duplicate,
            '249-C-CE' => PatchState::Misordered,
            '249-D-CE' => PatchState::Entitled,
            '249-E-CE' => PatchState::Applied,
        ]);

        $out = (new GithubFormatter())->format($report)->document;

        self::assertStringContainsString('::error title=missing::249-A-CE is not applied and not queued.', $out);
        self::assertStringContainsString('::error title=duplicate::249-B-CE', $out);
        self::assertStringContainsString('::error title=misordered::249-C-CE', $out);
        self::assertStringContainsString('::warning title=entitled::249-D-CE', $out);
        self::assertStringNotContainsString('249-E-CE', $out);
        self::assertStringContainsString(
            '::notice::Summary: 1 applied, 1 duplicate, 1 misordered, 1 missing, 1 entitled',
            $out,
        );
    }

    public function test_it_escapes_newlines_and_percent_signs_in_annotations(): void
    {
        $report = StateReports::of([], ["100% broken\r\nsecond line"]);

        $out = (new GithubFormatter())->format($report)->document;

        self::assertStringContainsString('::warning::100%25 broken%0D%0Asecond line', $out);
    }

    public function test_it_escapes_colons_and_commas_in_annotation_properties(): void
    {
        $report = StateReports::of(['249-A-CE' => PatchState::Missing]);
        $verify = new VerifyResult([
            new ReplayStep(ReportFixtures::entry(1, 'm2-hotfixes/a,b:c.patch'), ReplayOutcome::Failed, 'x'),
        ]);

        $out = (new GithubFormatter())->format($report, null, $verify)->document;

        self::assertStringContainsString('::error title=verify,file=m2-hotfixes/a%2Cb%3Ac.patch::', $out);
    }

    public function test_it_renders_a_markdown_job_summary_with_a_patch_table(): void
    {
        $output = (new GithubFormatter())->format(ReportFixtures::mixed());

        $summary = $output->artefacts[GithubFormatter::STEP_SUMMARY];
        self::assertSame(Golden::expected('github-summary.md', $summary), $summary);
        self::assertStringContainsString('| 249-VULN-39341-CE | CE | missing |', $summary);
    }

    public function test_it_reports_the_failing_verify_step_as_an_error_annotation(): void
    {
        $verify = new VerifyResult([
            new ReplayStep(
                ReportFixtures::entry(1, 'm2-hotfixes/ok.patch'),
                ReplayOutcome::AlreadyAppliedIgnoringPatchStatus,
            ),
            new ReplayStep(
                ReportFixtures::entry(2, 'm2-hotfixes/bad.patch'),
                ReplayOutcome::Failed,
                "Hunk #1 FAILED\n100%",
            ),
        ]);

        $out = (new GithubFormatter())->format(ReportFixtures::clean(), null, $verify)->document;

        self::assertStringContainsString(
            '::error title=verify,file=m2-hotfixes/bad.patch::'
                . 'm2-hotfixes/bad.patch would fail: Hunk #1 FAILED%0A100%25',
            $out,
        );
        self::assertStringContainsString('::warning title=verify,file=m2-hotfixes/ok.patch::', $out);
    }

    public function test_it_reports_failed_sync_actions_as_error_annotations(): void
    {
        $patch = ReportFixtures::patch('249-A-CE', '2026-10-13');
        $sync = new ExecutionResult([
            new ActionOutcome(new Download($patch, 'm2-hotfixes/a.patch'), ActionStatus::Failed, 'HTTP 500'),
            new ActionOutcome(new Warn('Check order.'), ActionStatus::Warning, 'Check order.'),
            new ActionOutcome(new Warn('Fine.'), ActionStatus::Done, 'Fine.'),
        ]);

        $out = (new GithubFormatter())->format(ReportFixtures::clean(), $sync)->document;

        self::assertStringContainsString(
            '::error title=sync::download 249-A-CE to m2-hotfixes/a.patch: HTTP 500',
            $out,
        );
        self::assertStringContainsString('::warning title=sync::Check order.', $out);
        self::assertStringNotContainsString('Fine.', $out);
    }
}
