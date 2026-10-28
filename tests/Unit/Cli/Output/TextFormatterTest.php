<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\Analysis\ReportSummary;
use Patchline\Cli\Output\FormattedOutput;
use Patchline\Cli\Output\Style;
use Patchline\Cli\Output\TextFormatter;
use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionState;
use Patchline\Installation\Edition;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\RegistryPatch;
use Patchline\Registry\RegistrySource;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextFormatter::class)]
#[CoversClass(Style::class)]
#[CoversClass(FormattedOutput::class)]
#[UsesClass(PatchReport::class)]
#[UsesClass(Report::class)]
#[UsesClass(ReportSummary::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(RegistryPatch::class)]
#[UsesClass(ActionOutcome::class)]
#[UsesClass(Download::class)]
#[UsesClass(Warn::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(ReplayOutcome::class)]
#[UsesClass(ReplayStep::class)]
#[UsesClass(VerifyResult::class)]
final class TextFormatterTest extends TestCase
{
    public function test_it_prints_each_sync_action_with_its_outcome(): void
    {
        $patch = ReportFixtures::patch('249-2026-10-001-CE', '2026-10-13');
        $download = new Download($patch, 'm2-hotfixes/2026-10-13_249-2026-10-001-CE.patch');
        $sync = new ExecutionResult([
            new ActionOutcome($download, ActionStatus::Done, 'Downloaded.'),
            new ActionOutcome(new Warn('Check the queue order.'), ActionStatus::Warning, 'Check the queue order.'),
        ]);

        $out = (new TextFormatter(Style::disabled()))->format(ReportFixtures::mixed(), $sync)->document;

        self::assertSame(Golden::expected('text-sync.txt', $out), $out);
        self::assertStringContainsString('[done] download 249-2026-10-001-CE to m2-hotfixes/2026-10-13_', $out);
    }

    public function test_it_prints_the_failing_verify_step_with_the_patch_output(): void
    {
        $ok = ReportFixtures::entry(1, 'm2-hotfixes/ok.patch');
        $bad = ReportFixtures::entry(2, 'm2-hotfixes/bad.patch');
        $verify = new VerifyResult([
            new ReplayStep($ok, ReplayOutcome::WouldApply),
            new ReplayStep($bad, ReplayOutcome::Failed, "Hunk #1 FAILED at 10.\n1 out of 1 hunk FAILED"),
        ], ['A note.']);

        $out = (new TextFormatter(Style::disabled()))->format(ReportFixtures::mixed(), null, $verify)->document;

        self::assertSame(Golden::expected('text-verify.txt', $out), $out);
        self::assertStringContainsString('    Hunk #1 FAILED at 10.', $out);
    }

    public function test_it_prints_one_line_per_patch_with_its_state(): void
    {
        $out = (new TextFormatter(Style::disabled()))->format(ReportFixtures::mixed())->document;

        self::assertSame(Golden::expected('text-mixed.txt', $out), $out);
        self::assertMatchesRegularExpression(
            '/^2026-10-13  CE  249-2026-10-001-CE  queued +m2-hotfixes\/\S+\.patch  2 CVEs$/m',
            $out,
        );
    }

    public function test_it_prints_the_next_step_when_patches_are_missing(): void
    {
        $out = (new TextFormatter(Style::disabled()))->format(ReportFixtures::mixed())->document;

        self::assertStringContainsString('Next: run patchline sync', $out);
    }

    public function test_it_prints_nothing_to_do_when_everything_is_applied(): void
    {
        $out = (new TextFormatter(Style::disabled()))->format(ReportFixtures::clean())->document;

        self::assertSame(Golden::expected('text-clean.txt', $out), $out);
    }

    public function test_it_prints_no_ansi_codes_when_style_is_disabled(): void
    {
        $out = (new TextFormatter(Style::disabled()))->format(ReportFixtures::mixed())->document;

        self::assertStringNotContainsString("\033", $out);
    }

    /** @return array<string, array{list<PatchState>, string}> */
    public static function queueProblemNextSteps(): array
    {
        return [
            'misordered' => [
                [PatchState::Misordered],
                'Next: run patchline sync --adopt to rename hand-copied patch files into release order.',
            ],
            'duplicate' => [
                [PatchState::Duplicate],
                'Next: run patchline sync --prune to remove duplicate m2-hotfixes copies.',
            ],
            'both' => [
                [PatchState::Misordered, PatchState::Duplicate],
                'Next: run patchline sync --adopt --prune to rename hand-copied patch files into release order'
                    . ' and remove duplicate m2-hotfixes copies.',
            ],
        ];
    }

    /** @param list<PatchState> $states */
    #[DataProvider('queueProblemNextSteps')]
    public function test_it_prints_an_actionable_next_step_for_queue_problems(array $states, string $expected): void
    {
        $patches = [];
        foreach ($states as $i => $state) {
            $patch = ReportFixtures::patch('249-2026-10-00' . $i . '-CE', '2026-10-13');
            $patches[] = new PatchReport($patch, $state, DetectionResult::of(DetectionState::NotApplied), [], []);
        }
        $report = new Report(
            '2.4.9',
            Edition::OpenSource,
            new InstalledComponents(['CE' => '2.4.9']),
            RegistrySource::Remote,
            $patches,
            new PatchQueue([]),
            [],
            [],
        );

        $out = (new TextFormatter(Style::disabled()))->format($report)->document;

        self::assertStringContainsString($expected . "\n", $out);
    }

    public function test_it_prints_ansi_codes_when_style_is_enabled(): void
    {
        $out = (new TextFormatter(Style::enabled()))->format(ReportFixtures::mixed())->document;

        self::assertStringContainsString("\033[", $out);
    }
}
