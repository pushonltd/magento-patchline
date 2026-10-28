<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Sync;

use Patchline\Analysis\AnalysisInput;
use Patchline\Analysis\Analyzer;
use Patchline\Analysis\PatchReport;
use Patchline\Analysis\Report;
use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionResults;
use Patchline\Detection\DetectionState;
use Patchline\Installation\Edition;
use Patchline\Installation\Installation;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use Patchline\Registry\DependencyGraph;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\Registry;
use Patchline\Registry\RegistryPatch;
use Patchline\Registry\RegistrySource;
use Patchline\Sync\Action\Action;
use Patchline\Sync\Action\Download;
use Patchline\Sync\Action\Remove;
use Patchline\Sync\Action\Rename;
use Patchline\Sync\Action\Warn;
use Patchline\Sync\Plan;
use Patchline\Sync\Planner;
use Patchline\Sync\SyncOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Planner::class)]
#[CoversClass(Plan::class)]
#[CoversClass(SyncOptions::class)]
#[CoversClass(Download::class)]
#[CoversClass(Rename::class)]
#[CoversClass(Remove::class)]
#[CoversClass(Warn::class)]
#[UsesClass(AnalysisInput::class)]
#[UsesClass(Analyzer::class)]
#[UsesClass(PatchReport::class)]
#[UsesClass(Report::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(DetectionResults::class)]
#[UsesClass(Installation::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(DependencyGraph::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryPatch::class)]
final class PlannerTest extends TestCase
{
    public function test_it_plans_a_download_for_every_missing_patch_in_dependency_order(): void
    {
        $report = $this->report([
            'C' => ['requires' => ['B'], 'released' => '2026-10-13'],
            'B' => ['requires' => ['A'], 'released' => '2026-09-08'],
            'A' => ['released' => '2026-08-11'],
        ]);

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame(['A', 'B', 'C'], array_map(
            static fn (Download $download): string => $download->patch->id,
            $this->downloads($plan),
        ));
    }

    public function test_it_names_downloaded_files_with_the_release_date_first(): void
    {
        $report = $this->report(['249-2026-10-001-CE' => ['released' => '2026-10-13']]);

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame(
            'm2-hotfixes/2026-10-13_249-2026-10-001-CE.patch',
            $this->downloads($plan)[0]->targetPath,
        );
    }

    public function test_it_plans_renames_of_hand_copied_files_only_when_adopt_is_enabled(): void
    {
        $report = $this->report(
            ['249-2026-09-001-CE' => ['released' => '2026-09-08']],
            [$this->hotfix('m2-hotfixes/249-2026-09-001-CE.patch', '249-2026-09-001-CE')],
            ['249-2026-09-001-CE' => DetectionState::Applied],
        );

        $without = (new Planner())->plan($report, new SyncOptions());
        $with = (new Planner())->plan($report, new SyncOptions(adopt: true));

        self::assertSame([], $this->actionsOf($without, Rename::class));
        $renames = $this->actionsOf($with, Rename::class);
        self::assertCount(1, $renames);
        self::assertSame('m2-hotfixes/249-2026-09-001-CE.patch', $renames[0]->from);
        self::assertSame('m2-hotfixes/2026-09-08_249-2026-09-001-CE.patch', $renames[0]->to);
        self::assertSame('sha-249-2026-09-001-CE', $renames[0]->sha256);
    }

    public function test_it_plans_removal_of_the_m2_hotfixes_copy_of_a_duplicate_only_when_prune_is_enabled(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [$this->cloud('vendor/cloud/A.patch', 'A'), $this->hotfix('m2-hotfixes/A.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $without = (new Planner())->plan($report, new SyncOptions());
        $with = (new Planner())->plan($report, new SyncOptions(prune: true));

        self::assertSame([], $this->actionsOf($without, Remove::class));
        $removals = $this->actionsOf($with, Remove::class);
        self::assertCount(1, $removals);
        self::assertSame('m2-hotfixes/A.patch', $removals[0]->path);
        self::assertSame('sha-A', $removals[0]->sha256);
    }

    public function test_it_keeps_the_copy_with_the_target_name_when_all_copies_of_a_duplicate_are_hand_copied(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [
                $this->hotfix('m2-hotfixes/2026-09-08_A.patch', 'A'),
                $this->hotfix('m2-hotfixes/A.patch', 'A'),
                $this->hotfix('m2-hotfixes/B.patch', 'A'),
            ],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(prune: true));

        self::assertSame(
            ['m2-hotfixes/A.patch', 'm2-hotfixes/B.patch'],
            array_map(static fn (Remove $remove): string => $remove->path, $this->actionsOf($plan, Remove::class)),
        );
    }

    public function test_it_keeps_the_first_copy_in_byte_order_when_no_copy_has_the_target_name(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [$this->hotfix('m2-hotfixes/b.patch', 'A'), $this->hotfix('m2-hotfixes/a.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(prune: true));

        self::assertSame(
            ['m2-hotfixes/b.patch'],
            array_map(static fn (Remove $remove): string => $remove->path, $this->actionsOf($plan, Remove::class)),
        );
    }

    public function test_it_never_plans_removal_of_a_cloud_patches_file(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [$this->cloud('vendor/cloud/one.patch', 'A'), $this->cloud('vendor/cloud/two.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(prune: true));

        self::assertSame([], $this->actionsOf($plan, Remove::class));
    }

    public function test_it_never_renames_a_file_it_plans_to_remove(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [$this->hotfix('m2-hotfixes/a.patch', 'A'), $this->hotfix('m2-hotfixes/b.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(adopt: true, prune: true));

        self::assertSame(['m2-hotfixes/b.patch'], array_map(
            static fn (Remove $remove): string => $remove->path,
            $this->actionsOf($plan, Remove::class),
        ));
        self::assertSame(['m2-hotfixes/a.patch'], array_map(
            static fn (Rename $rename): string => $rename->from,
            $this->actionsOf($plan, Rename::class),
        ));
    }

    public function test_it_warns_instead_of_renaming_when_the_target_name_already_exists(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [
                $this->hotfix('m2-hotfixes/2026-09-08_A.patch', 'other'),
                $this->hotfix('m2-hotfixes/A.patch', 'A'),
            ],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(adopt: true));

        self::assertSame([], $this->actionsOf($plan, Rename::class));
        $warnings = $this->actionsOf($plan, Warn::class);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('m2-hotfixes/A.patch', $warnings[0]->message);
        self::assertStringContainsString('m2-hotfixes/2026-09-08_A.patch already exists', $warnings[0]->message);
        self::assertSame('A', $warnings[0]->patchId);
    }

    public function test_it_warns_instead_of_downloading_when_the_target_name_holds_different_content(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [$this->hotfix('m2-hotfixes/2026-09-08_A.patch', 'other')],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame([], $this->downloads($plan));
        $warnings = $this->actionsOf($plan, Warn::class);
        self::assertCount(1, $warnings);
        self::assertStringContainsString(
            'm2-hotfixes/2026-09-08_A.patch exists with different content',
            $warnings[0]->message,
        );
    }

    public function test_it_warns_when_a_planned_download_would_be_queued_before_one_of_its_prerequisites(): void
    {
        $report = $this->report(
            [
                'A' => ['released' => '2026-09-08'],
                'B' => ['requires' => ['A'], 'released' => '2026-10-13'],
            ],
            [$this->hotfix('m2-hotfixes/A-legacy.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame(
            ['m2-hotfixes/2026-10-13_B.patch', 'm2-hotfixes/A-legacy.patch'],
            array_map(static fn (QueueEntry $entry): string => $entry->path, $plan->resultingQueue()),
        );
        $warnings = $this->actionsOf($plan, Warn::class);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('m2-hotfixes/2026-10-13_B.patch', $warnings[0]->message);
        self::assertStringContainsString('m2-hotfixes/A-legacy.patch', $warnings[0]->message);
        self::assertStringContainsString('--adopt', $warnings[0]->message);
    }

    public function test_it_does_not_warn_about_the_queue_order_when_adopt_renames_the_prerequisite(): void
    {
        $report = $this->report(
            [
                'A' => ['released' => '2026-09-08'],
                'B' => ['requires' => ['A'], 'released' => '2026-10-13'],
            ],
            [$this->hotfix('m2-hotfixes/A-legacy.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(adopt: true));

        self::assertSame([], $this->actionsOf($plan, Warn::class));
        self::assertSame(
            ['m2-hotfixes/2026-09-08_A.patch', 'm2-hotfixes/2026-10-13_B.patch'],
            array_map(static fn (QueueEntry $entry): string => $entry->path, $plan->resultingQueue()),
        );
    }

    public function test_it_tells_to_rename_by_hand_when_adopt_cannot_fix_the_queue_order(): void
    {
        $report = $this->report(
            [
                'A' => ['released' => '2026-09-08'],
                'B' => ['requires' => ['A'], 'released' => '2026-10-13'],
            ],
            [
                $this->hotfix('m2-hotfixes/2026-09-08_A.patch', 'other'),
                $this->hotfix('m2-hotfixes/A.patch', 'A'),
            ],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(adopt: true));

        $messages = array_map(static fn (Warn $warn): string => $warn->message, $this->actionsOf($plan, Warn::class));
        self::assertContains(
            'm2-hotfixes/2026-10-13_B.patch would be applied before m2-hotfixes/A.patch, but B requires A. '
            . 'Rename the files by hand so that m2-hotfixes/A.patch sorts first.',
            $messages,
        );
    }

    public function test_it_neither_renames_nor_orders_a_hand_copied_file_of_a_patch_that_does_not_apply(): void
    {
        $report = $this->report(
            [
                'A' => ['released' => '2026-09-08'],
                'B' => ['requires' => ['A'], 'released' => '2026-10-13'],
            ],
            [
                $this->hotfix('m2-hotfixes/B-legacy.patch', 'B'),
                $this->hotfix('m2-hotfixes/A-legacy.patch', 'A'),
            ],
            ['A' => DetectionState::Applied, 'B' => DetectionState::NotApplicable],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(adopt: true));

        self::assertSame(
            ['m2-hotfixes/2026-09-08_A.patch' => 'm2-hotfixes/A-legacy.patch'],
            array_column(
                array_map(
                    static fn (Rename $rename): array => ['to' => $rename->to, 'from' => $rename->from],
                    $this->actionsOf($plan, Rename::class),
                ),
                'from',
                'to',
            ),
        );
        self::assertSame([], $this->actionsOf($plan, Warn::class));
    }

    public function test_it_puts_cloud_patches_entries_ahead_of_m2_hotfixes_in_the_resulting_queue(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08'], 'B' => ['requires' => ['A'], 'released' => '2026-10-13']],
            [$this->hotfix('m2-hotfixes/zzz.patch', 'other'), $this->cloud('vendor/cloud/A.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame(
            ['vendor/cloud/A.patch', 'm2-hotfixes/2026-10-13_B.patch', 'm2-hotfixes/zzz.patch'],
            array_map(static fn (QueueEntry $entry): string => $entry->path, $plan->resultingQueue()),
        );
        self::assertSame([1, 2, 3], array_map(
            static fn (QueueEntry $entry): int => $entry->position,
            $plan->resultingQueue(),
        ));
    }

    public function test_it_warns_about_requirements_it_cannot_satisfy(): void
    {
        $report = $this->report(
            [
                'U' => ['released' => '2026-08-11'],
                'B' => ['requires' => ['U', 'gone'], 'released' => '2026-10-13'],
            ],
            [],
            ['U' => DetectionResult::unknown(['dry run failed'])],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        $messages = array_map(static fn (Warn $warn): string => $warn->message, $this->actionsOf($plan, Warn::class));
        self::assertCount(3, $messages);
        self::assertStringContainsString('dry run failed', $messages[0]);
        self::assertStringContainsString('B requires U', $messages[1]);
        self::assertStringContainsString('B requires gone', $messages[2]);
    }

    public function test_it_does_not_warn_about_a_requirement_that_the_plan_downloads(): void
    {
        $report = $this->report([
            'A' => ['released' => '2026-08-11'],
            'B' => ['requires' => ['A'], 'released' => '2026-10-13'],
        ]);

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame([], $this->actionsOf($plan, Warn::class));
    }

    public function test_it_plans_downloads_of_entitled_patches_only_when_entitled_patches_are_included(): void
    {
        $specs = ['EE' => ['released' => '2026-10-13', 'entitlements' => ['ee']]];

        $excluded = (new Planner())->plan($this->report($specs), new SyncOptions());
        $included = (new Planner())->plan(
            $this->report($specs, [], [], true),
            new SyncOptions(includeEntitled: true),
        );

        self::assertSame([], $this->downloads($excluded));
        $warnings = $this->actionsOf($excluded, Warn::class);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('--include-entitled', $warnings[0]->message);
        self::assertCount(1, $this->downloads($included));
        self::assertSame([], $this->actionsOf($included, Warn::class));
    }

    public function test_it_orders_removals_before_renames_before_downloads_before_warnings(): void
    {
        $report = $this->report(
            [
                'A' => ['released' => '2026-09-08'],
                'M' => ['released' => '2026-10-13'],
                'U' => ['released' => '2026-10-14'],
            ],
            [
                $this->hotfix('m2-hotfixes/dup-1.patch', 'A'),
                $this->hotfix('m2-hotfixes/dup-2.patch', 'A'),
            ],
            ['A' => DetectionState::Applied, 'U' => DetectionState::Unknown],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(adopt: true, prune: true));

        self::assertSame(
            [Remove::class, Rename::class, Download::class, Warn::class],
            array_map(static fn (Action $action): string => $action::class, $plan->actions),
        );
    }

    public function test_it_warns_with_the_flag_that_would_fix_a_duplicate(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [$this->cloud('vendor/cloud/A.patch', 'A'), $this->hotfix('m2-hotfixes/2026-09-08_A.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        $warnings = $this->actionsOf($plan, Warn::class);
        self::assertCount(1, $warnings);
        self::assertStringContainsString('--prune', $warnings[0]->message);
    }

    public function test_it_warns_that_a_duplicate_of_cloud_patches_files_needs_a_manual_fix(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [$this->cloud('vendor/cloud/one.patch', 'A'), $this->cloud('vendor/cloud/two.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(prune: true));

        $warnings = $this->actionsOf($plan, Warn::class);
        self::assertCount(1, $warnings);
        self::assertStringNotContainsString('--prune', $warnings[0]->message);
        self::assertStringContainsString('magento-cloud-patches', $warnings[0]->message);
    }

    public function test_it_does_not_claim_a_target_name_twice_when_two_files_are_adopted(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [$this->hotfix('m2-hotfixes/a.patch', 'A'), $this->hotfix('m2-hotfixes/b.patch', 'A')],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(adopt: true));

        self::assertCount(1, $this->actionsOf($plan, Rename::class));
        self::assertCount(2, $this->actionsOf($plan, Warn::class));
    }

    public function test_it_serialises_a_plan_for_dry_run_output(): void
    {
        $report = $this->report(['A' => ['released' => '2026-09-08']]);

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame([
            'actions' => [
                [
                    'action' => 'download',
                    'patch' => 'A',
                    'path' => 'm2-hotfixes/2026-09-08_A.patch',
                    'sha256' => 'sha-A',
                ],
            ],
            'resulting_queue' => [
                ['position' => 1, 'path' => 'm2-hotfixes/2026-09-08_A.patch', 'sha256' => 'sha-A'],
            ],
        ], $plan->toArray());
        self::assertTrue($plan->hasChanges());
        self::assertSame('download A to m2-hotfixes/2026-09-08_A.patch', $plan->actions[0]->describe());
    }

    public function test_it_serialises_every_action_kind(): void
    {
        $patch = new RegistryPatch('A', [], 'ce', [], [], new \DateTimeImmutable('2026-01-01'), 'A.diff', 'sha-A');

        self::assertSame(
            ['action' => 'rename', 'from' => 'x', 'to' => 'y', 'sha256' => 's'],
            (new Rename('x', 'y', 's'))->toArray(),
        );
        self::assertSame('rename x to y', (new Rename('x', 'y', 's'))->describe());
        self::assertSame(['action' => 'remove', 'path' => 'x', 'sha256' => 's'], (new Remove('x', 's'))->toArray());
        self::assertSame('remove x', (new Remove('x', 's'))->describe());
        self::assertSame(['action' => 'warn', 'message' => 'm', 'patch' => null], (new Warn('m'))->toArray());
        self::assertSame('m', (new Warn('m'))->describe());
        self::assertSame('A', (new Download($patch, 'p'))->toArray()['patch']);
        $plan = new Plan([new Warn('m')], []);
        self::assertFalse($plan->hasChanges());
        self::assertCount(1, $plan->warnings());
    }

    public function test_it_explains_every_duplicate_it_cannot_prune_in_one_plan(): void
    {
        $report = $this->report(
            [
                'N' => ['released' => '2026-08-01'],
                'C' => ['released' => '2026-08-02'],
                'E1' => ['released' => '2026-08-03'],
                'E2' => ['released' => '2026-08-04'],
            ],
            [
                $this->cloud('vendor/cloud/c1.patch', 'C'),
                $this->cloud('vendor/cloud/c2.patch', 'C'),
                $this->hotfix('m2-hotfixes/e1-b.patch', 'E1'),
                $this->hotfix('m2-hotfixes/e1-a.patch', 'E1'),
                $this->hotfix('m2-hotfixes/e2-b.patch', 'E2'),
                $this->hotfix('m2-hotfixes/e2-a.patch', 'E2'),
            ],
            [
                'N' => DetectionState::Applied,
                'C' => DetectionState::Applied,
                'E1' => DetectionState::Applied,
                'E2' => DetectionState::Applied,
            ],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame([
            'C is queued more than once by magento-cloud-patches itself '
                . '(vendor/cloud/c1.patch, vendor/cloud/c2.patch). '
                . 'Patchline does not touch those files; fix it in the cloud patches configuration.',
            'E1 is queued 2 times (m2-hotfixes/e1-b.patch, m2-hotfixes/e1-a.patch). '
                . 'Run sync with --prune to remove m2-hotfixes/e1-b.patch.',
            'E2 is queued 2 times (m2-hotfixes/e2-b.patch, m2-hotfixes/e2-a.patch). '
                . 'Run sync with --prune to remove m2-hotfixes/e2-b.patch.',
        ], $this->messages($plan));
    }

    public function test_it_removes_every_hotfix_copy_next_to_a_cloud_patches_file_and_drops_them_from_the_queue(): void
    {
        $report = $this->report(
            [
                'N' => ['released' => '2026-08-01'],
                'D' => ['released' => '2026-08-02'],
                'E' => ['released' => '2026-08-03'],
            ],
            [
                $this->cloud('vendor/cloud/d.patch', 'D'),
                $this->hotfix('m2-hotfixes/d-1.patch', 'D'),
                $this->hotfix('m2-hotfixes/d-2.patch', 'D'),
                $this->hotfix('m2-hotfixes/e-1.patch', 'E'),
                $this->hotfix('m2-hotfixes/e-2.patch', 'E'),
                $this->hotfix('m2-hotfixes/n.patch', 'N'),
            ],
            ['N' => DetectionState::Applied, 'D' => DetectionState::Applied, 'E' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(prune: true));

        self::assertSame(
            ['m2-hotfixes/d-1.patch', 'm2-hotfixes/d-2.patch', 'm2-hotfixes/e-2.patch'],
            array_map(static fn (Remove $remove): string => $remove->path, $this->actionsOf($plan, Remove::class)),
        );
        self::assertSame(
            ['vendor/cloud/d.patch', 'm2-hotfixes/e-1.patch', 'm2-hotfixes/n.patch'],
            array_map(static fn (QueueEntry $entry): string => $entry->path, $plan->resultingQueue()),
        );
        self::assertSame([1, 2, 3], array_map(
            static fn (QueueEntry $entry): int => $entry->position,
            $plan->resultingQueue(),
        ));
    }

    public function test_it_keeps_the_copy_with_the_target_name_wherever_it_sorts_among_the_copies(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [
                $this->hotfix('m2-hotfixes/0-first.patch', 'A'),
                $this->hotfix('m2-hotfixes/2026-09-08_A.patch', 'A'),
                $this->hotfix('m2-hotfixes/z-last.patch', 'A'),
            ],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(prune: true));

        self::assertSame(
            ['m2-hotfixes/0-first.patch', 'm2-hotfixes/z-last.patch'],
            array_map(static fn (Remove $remove): string => $remove->path, $this->actionsOf($plan, Remove::class)),
        );
    }

    public function test_it_explains_every_blocked_rename_of_a_patch_that_is_hand_copied_three_times(): void
    {
        $report = $this->report(
            ['A' => ['released' => '2026-09-08']],
            [
                $this->hotfix('m2-hotfixes/a1.patch', 'A'),
                $this->hotfix('m2-hotfixes/a2.patch', 'A'),
                $this->hotfix('m2-hotfixes/a3.patch', 'A'),
            ],
            ['A' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(adopt: true));

        self::assertSame(['m2-hotfixes/a1.patch'], array_map(
            static fn (Rename $rename): string => $rename->from,
            $this->actionsOf($plan, Rename::class),
        ));
        self::assertContains(
            'Cannot rename m2-hotfixes/a2.patch: m2-hotfixes/2026-09-08_A.patch already exists. '
            . 'Rename or remove one of them by hand.',
            $this->messages($plan),
        );
        self::assertContains(
            'Cannot rename m2-hotfixes/a3.patch: m2-hotfixes/2026-09-08_A.patch already exists. '
            . 'Rename or remove one of them by hand.',
            $this->messages($plan),
        );
    }

    public function test_it_never_adopts_a_cloud_patches_file_or_one_that_already_has_the_target_name(): void
    {
        $report = $this->report(
            [
                'A' => ['released' => '2026-09-08'],
                'B' => ['released' => '2026-09-09'],
            ],
            [
                $this->cloud('vendor/cloud/a.patch', 'A'),
                $this->hotfix('m2-hotfixes/2026-09-09_B.patch', 'B'),
            ],
            ['A' => DetectionState::Applied, 'B' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions(adopt: true));

        self::assertSame([], $this->actionsOf($plan, Rename::class));
        self::assertSame([], $this->actionsOf($plan, Warn::class));
    }

    public function test_it_explains_each_patch_it_cannot_download_and_still_downloads_the_others(): void
    {
        $report = $this->report(
            [
                'OK' => ['released' => '2026-08-01'],
                'EE' => ['released' => '2026-08-02', 'entitlements' => ['ee']],
                'UNK' => ['released' => '2026-08-03'],
                'UNK2' => ['released' => '2026-08-04'],
                'TAKEN' => ['released' => '2026-08-05'],
                'LAST' => ['released' => '2026-08-06'],
            ],
            [$this->hotfix('m2-hotfixes/2026-08-05_TAKEN.patch', 'other')],
            [
                'UNK' => DetectionResult::unknown(['patch said no', 'twice']),
                'UNK2' => DetectionState::Unknown,
            ],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame(
            ['m2-hotfixes/2026-08-01_OK.patch', 'm2-hotfixes/2026-08-06_LAST.patch'],
            array_map(static fn (Download $download): string => $download->targetPath, $this->downloads($plan)),
        );
        self::assertSame([
            'Cannot download TAKEN: m2-hotfixes/2026-08-05_TAKEN.patch exists with different content. '
                . 'Move it away and run sync again.',
            'EE needs an entitlement this store may not have, so it was not downloaded. '
                . 'Run sync with --include-entitled if you are entitled to it.',
            'UNK was not downloaded because patchline cannot tell whether it is already applied '
                . '(patch said no; twice). Check it by hand.',
            'UNK2 was not downloaded because patchline cannot tell whether it is already applied. '
                . 'Check it by hand.',
        ], $this->messages($plan));
    }

    public function test_it_downloads_only_missing_patches_even_when_entitled_patches_are_included(): void
    {
        $report = $this->report(
            [
                'APPLIED' => ['released' => '2026-08-01'],
                'QUEUED' => ['released' => '2026-08-02'],
                'MISSING' => ['released' => '2026-08-03'],
            ],
            [$this->hotfix('m2-hotfixes/queued.patch', 'QUEUED')],
            ['APPLIED' => DetectionState::Applied],
            true,
        );

        $plan = (new Planner())->plan($report, new SyncOptions(includeEntitled: true));

        self::assertSame(['MISSING'], array_map(
            static fn (Download $download): string => $download->patch->id,
            $this->downloads($plan),
        ));
    }

    public function test_it_names_each_unsatisfiable_requirement_of_each_downloaded_patch(): void
    {
        $report = $this->report(
            [
                'U1' => ['released' => '2026-07-01'],
                'U2' => ['released' => '2026-07-02'],
                'APPLIED' => ['released' => '2026-07-03'],
                'B' => ['requires' => ['U1', 'U2'], 'released' => '2026-10-13'],
                'C' => ['requires' => ['U1'], 'released' => '2026-10-14'],
            ],
            [],
            [
                'U1' => DetectionState::Unknown,
                'U2' => DetectionState::Unknown,
                'APPLIED' => DetectionState::Applied,
            ],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        $unsatisfiable = array_values(array_filter(
            $this->messages($plan),
            static fn (string $message): bool => str_contains($message, 'which sync cannot install'),
        ));
        self::assertSame([
            'B requires U1, which sync cannot install. B may not apply until U1 is dealt with by hand.',
            'B requires U2, which sync cannot install. B may not apply until U2 is dealt with by hand.',
            'C requires U1, which sync cannot install. C may not apply until U1 is dealt with by hand.',
        ], $unsatisfiable);
    }

    public function test_it_uses_the_first_queue_entry_of_a_patch_to_judge_the_queue_order(): void
    {
        $report = $this->report(
            [
                'A' => ['released' => '2026-09-08'],
                'B' => ['requires' => ['A'], 'released' => '2026-10-13'],
            ],
            [
                $this->hotfix('m2-hotfixes/2026-09-08_A.patch', 'A'),
                $this->hotfix('m2-hotfixes/2026-10-13_B.patch', 'B'),
                $this->hotfix('m2-hotfixes/z-A-again.patch', 'A'),
            ],
            ['A' => DetectionState::Applied, 'B' => DetectionState::Applied],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame([], array_filter(
            $this->messages($plan),
            static fn (string $message): bool => str_contains($message, 'would be applied before'),
        ));
    }

    // phpcs:ignore Generic.Files.LineLength.TooLong -- the test name is the requirement text
    public function test_it_warns_about_every_prerequisite_a_queued_patch_precedes_and_skips_patches_that_do_not_apply(): void
    {
        $report = $this->report(
            [
                'GONE' => ['released' => '2026-09-01'],
                'X' => ['released' => '2026-09-02'],
                'A' => ['released' => '2026-09-08'],
                'A2' => ['released' => '2026-09-09'],
                'B' => ['requires' => ['GONE', 'MISSING-FROM-REPORT', 'A', 'A2'], 'released' => '2026-10-13'],
                'C' => ['requires' => ['A'], 'released' => '2026-10-14'],
            ],
            [
                $this->hotfix('m2-hotfixes/2026-10-13_B.patch', 'B'),
                $this->hotfix('m2-hotfixes/2026-10-14_C.patch', 'C'),
                $this->hotfix('m2-hotfixes/zz-A2.patch', 'A2'),
                $this->hotfix('m2-hotfixes/zz-A.patch', 'A'),
            ],
            [
                'GONE' => DetectionState::NotApplicable,
                'X' => DetectionState::Applied,
                'A' => DetectionState::Applied,
                'A2' => DetectionState::Applied,
                'B' => DetectionState::Applied,
                'C' => DetectionState::Applied,
            ],
        );

        $plan = (new Planner())->plan($report, new SyncOptions());

        self::assertSame([
            'm2-hotfixes/2026-10-13_B.patch would be applied before m2-hotfixes/zz-A.patch, but B requires A. '
                . 'Run sync with --adopt to rename hand-copied files into release order.',
            'm2-hotfixes/2026-10-13_B.patch would be applied before m2-hotfixes/zz-A2.patch, but B requires A2. '
                . 'Run sync with --adopt to rename hand-copied files into release order.',
            'm2-hotfixes/2026-10-14_C.patch would be applied before m2-hotfixes/zz-A.patch, but C requires A. '
                . 'Run sync with --adopt to rename hand-copied files into release order.',
        ], $this->messages($plan));
    }

    /**
     * @param array<string, array{requires?: list<string>, released?: string, entitlements?: list<string>}> $specs
     * @param list<QueueEntry> $queue
     * @param array<string, DetectionState|DetectionResult> $detections
     */
    private function report(
        array $specs,
        array $queue = [],
        array $detections = [],
        bool $includeEntitled = false,
    ): Report {
        $patches = [];
        foreach ($specs as $id => $spec) {
            $patches[$id] = new RegistryPatch(
                $id,
                ['2.4.9'],
                'ce',
                $spec['requires'] ?? [],
                [],
                new \DateTimeImmutable($spec['released'] ?? '2026-01-01'),
                $id . '.diff',
                'sha-' . $id,
                $spec['entitlements'] ?? [],
            );
        }
        $registry = new Registry('1', 'https://example.test', [], $patches);
        $results = [];
        foreach (array_keys($patches) as $id) {
            $detection = $detections[$id] ?? DetectionState::NotApplied;
            $results[$id] = $detection instanceof DetectionResult ? $detection : DetectionResult::of($detection);
        }
        $entries = [];
        foreach ($queue as $index => $entry) {
            $entries[] = $entry->atPosition($index + 1);
        }

        return (new Analyzer())->analyze(new AnalysisInput(
            new Installation('/store', [], [], Edition::OpenSource, '2.4.9', null, false),
            new InstalledComponents(['ce' => '2.4.9']),
            array_values($patches),
            new DependencyGraph($registry, array_keys($patches)),
            new DetectionResults($results),
            new PatchQueue($entries),
            RegistrySource::Cache,
            $includeEntitled,
            [],
        ));
    }

    private function hotfix(string $path, string $patchId): QueueEntry
    {
        return new QueueEntry(0, QueueOrigin::Hotfix, null, $path, 'sha-' . $patchId);
    }

    private function cloud(string $path, string $patchId): QueueEntry
    {
        return new QueueEntry(0, QueueOrigin::CloudRequired, 'title', $path, 'sha-' . $patchId);
    }

    /** @return list<string> */
    private function messages(Plan $plan): array
    {
        return array_map(static fn (Warn $warn): string => $warn->message, $this->actionsOf($plan, Warn::class));
    }

    /** @return list<Download> */
    private function downloads(Plan $plan): array
    {
        return $this->actionsOf($plan, Download::class);
    }

    /**
     * @template T of Action
     * @param class-string<T> $class
     * @return list<T>
     */
    private function actionsOf(Plan $plan, string $class): array
    {
        $found = [];
        foreach ($plan->actions as $action) {
            if (!($action instanceof $class)) {
                continue;
            }

            $found[] = $action;
        }

        return $found;
    }
}
