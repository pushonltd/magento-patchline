<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Analysis;

use Patchline\Analysis\AnalysisInput;
use Patchline\Analysis\Analyzer;
use Patchline\Analysis\CveStatus;
use Patchline\Analysis\PatchReport;
use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\Analysis\ReportSummary;
use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionResults;
use Patchline\Detection\DetectionState;
use Patchline\Installation\Edition;
use Patchline\Installation\Installation;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use Patchline\Registry\DependencyGraph;
use Patchline\Registry\GraphProblem;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\Registry;
use Patchline\Registry\RegistryPatch;
use Patchline\Registry\RegistrySource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Analyzer::class)]
#[CoversClass(AnalysisInput::class)]
#[CoversClass(PatchReport::class)]
#[CoversClass(Report::class)]
#[CoversClass(ReportSummary::class)]
#[CoversClass(PatchState::class)]
#[CoversClass(CveStatus::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(DetectionResults::class)]
#[UsesClass(Installation::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(DependencyGraph::class)]
#[UsesClass(GraphProblem::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(Registry::class)]
#[UsesClass(RegistryPatch::class)]
final class AnalyzerTest extends TestCase
{
    public function test_it_reports_a_patch_found_in_the_code_as_applied(): void
    {
        $report = $this->analyze(['A' => []], ['A' => DetectionState::Applied], []);

        self::assertSame(PatchState::Applied, $report->patches[0]->state);
    }

    public function test_it_reports_a_patch_that_is_only_in_the_queue_as_queued(): void
    {
        $report = $this->analyze(['A' => []], ['A' => DetectionState::NotApplied], ['A']);

        self::assertSame(PatchState::Queued, $report->patches[0]->state);
        self::assertSame(['m2-hotfixes/A.patch'], array_map(
            static fn (QueueEntry $entry): string => $entry->path,
            $report->patches[0]->queueEntries,
        ));
    }

    // phpcs:ignore Generic.Files.LineLength.TooLong -- test name is the requirement text
    public function test_it_reports_a_queued_patch_whose_detection_is_unknown_as_queued_and_keeps_the_warnings(): void
    {
        $report = $this->analyze(
            ['A' => []],
            ['A' => DetectionResult::unknown(['dry run failed'])],
            ['A'],
        );

        self::assertSame(PatchState::Queued, $report->patches[0]->state);
        self::assertSame(['dry run failed'], $report->patches[0]->detection->warnings);
    }

    public function test_it_reports_a_patch_queued_by_cloud_patches_and_m2_hotfixes_as_duplicate(): void
    {
        $report = $this->analyze(['A' => []], ['A' => DetectionState::NotApplied], ['A', 'A']);

        self::assertSame(PatchState::Duplicate, $report->patches[0]->state);
        self::assertCount(2, $report->patches[0]->queueEntries);
    }

    public function test_it_reports_a_patch_queued_before_its_prerequisite_as_misordered(): void
    {
        $report = $this->analyze(
            ['A' => [], 'B' => ['requires' => ['A']]],
            ['A' => DetectionState::NotApplied, 'B' => DetectionState::NotApplied],
            ['B', 'A'],
        );

        self::assertSame(PatchState::Misordered, $this->state($report, 'B'));
        self::assertSame(PatchState::Queued, $this->state($report, 'A'));
    }

    public function test_it_reports_an_applied_patch_queued_before_its_queued_prerequisite_as_misordered(): void
    {
        $report = $this->analyze(
            ['A' => [], 'B' => ['requires' => ['A']]],
            ['A' => DetectionState::Applied, 'B' => DetectionState::Applied],
            ['B', 'A'],
        );

        self::assertSame(PatchState::Misordered, $this->state($report, 'B'));
    }

    // phpcs:ignore Generic.Files.LineLength.TooLong -- the test name is the requirement text
    public function test_it_does_not_call_a_patch_misordered_because_of_a_prerequisite_outside_the_analysed_patches(): void
    {
        $a = $this->registryPatch('A', []);
        $b = $this->registryPatch('B', ['A']);
        $registry = new Registry('1', 'https://example.test', [], ['A' => $a, 'B' => $b]);

        $report = (new Analyzer())->analyze(new AnalysisInput(
            new Installation('/store', [], [], Edition::OpenSource, '2.4.9', null, false),
            new InstalledComponents(['ce' => '2.4.9']),
            [$b],
            new DependencyGraph($registry, ['A', 'B']),
            new DetectionResults(['B' => DetectionResult::of(DetectionState::Applied)]),
            new PatchQueue([
                new QueueEntry(1, QueueOrigin::Hotfix, null, 'm2-hotfixes/B.patch', 'sha-B'),
                new QueueEntry(2, QueueOrigin::Hotfix, null, 'm2-hotfixes/A.patch', 'sha-A'),
            ]),
            RegistrySource::Cache,
            false,
            [],
        ));

        self::assertSame(PatchState::Applied, $this->state($report, 'B'));
    }

    public function test_it_reports_a_not_applicable_patch_as_not_applicable_even_when_it_is_queued(): void
    {
        $report = $this->analyze(['A' => []], ['A' => DetectionState::NotApplicable], ['A', 'A']);

        self::assertSame(PatchState::NotApplicable, $report->patches[0]->state);
    }

    public function test_it_reports_a_patch_that_is_neither_applied_nor_queued_as_missing(): void
    {
        $report = $this->analyze(
            ['A' => [], 'B' => ['requires' => ['A']]],
            ['A' => DetectionState::NotApplied, 'B' => DetectionState::NotApplied],
            ['B'],
        );

        self::assertSame(PatchState::Missing, $this->state($report, 'A'));
        self::assertSame(PatchState::Queued, $this->state($report, 'B'));
        self::assertSame(['A'], $report->patches[1]->unmetRequirements);
    }

    public function test_it_reports_an_unknown_patch_that_is_not_queued_as_unknown(): void
    {
        $report = $this->analyze(['A' => []], ['A' => DetectionState::Unknown], []);

        self::assertSame(PatchState::Unknown, $report->patches[0]->state);
    }

    public function test_it_lists_requirements_outside_the_scope_as_unmet(): void
    {
        $report = $this->analyze(
            ['B' => ['requires' => ['GONE']]],
            ['B' => DetectionState::Applied],
            [],
        );

        self::assertSame(['GONE'], $report->patches[0]->unmetRequirements);
    }

    public function test_it_ignores_a_not_applicable_prerequisite_when_checking_the_queue_order(): void
    {
        $report = $this->analyze(
            ['A' => [], 'B' => ['requires' => ['A']]],
            ['A' => DetectionState::NotApplicable, 'B' => DetectionState::NotApplied],
            ['B', 'A'],
        );

        self::assertSame(PatchState::Queued, $this->state($report, 'B'));
    }

    // phpcs:ignore Generic.Files.LineLength.TooLong -- test name is the requirement text
    public function test_it_reports_an_ee_only_patch_on_open_source_as_entitled_unless_entitled_patches_are_included(): void
    {
        $patches = ['A' => ['entitlements' => ['ee']]];
        $detections = ['A' => DetectionState::NotApplied];

        $excluded = $this->analyze($patches, $detections, []);
        $included = $this->analyze($patches, $detections, [], includeEntitled: true);
        $entitledStore = $this->analyze(
            $patches,
            $detections,
            [],
            packages: ['magento/product-enterprise-edition' => '2.4.9'],
        );

        self::assertSame(PatchState::Entitled, $excluded->patches[0]->state);
        self::assertSame(PatchState::Missing, $included->patches[0]->state);
        self::assertSame(PatchState::Missing, $entitledStore->patches[0]->state);
    }

    public function test_it_treats_a_b2b_entitlement_as_met_by_the_b2b_extension_and_unknown_ones_as_lacking(): void
    {
        $detections = ['A' => DetectionState::NotApplied];

        $packages = ['magento/extension-b2b' => '1'];
        $b2b = $this->analyze(['A' => ['entitlements' => ['b2b']]], $detections, [], packages: $packages);
        $other = $this->analyze(['A' => ['entitlements' => ['vip']]], $detections, [], packages: $packages);

        self::assertSame(PatchState::Missing, $b2b->patches[0]->state);
        self::assertSame(PatchState::Entitled, $other->patches[0]->state);
    }

    public function test_it_marks_a_cve_vulnerable_when_any_patch_fixing_it_is_missing(): void
    {
        $report = $this->analyze(
            ['A' => ['cves' => ['CVE-1', 'CVE-2']], 'B' => ['cves' => ['CVE-1']]],
            ['A' => DetectionState::NotApplied, 'B' => DetectionState::Applied],
            [],
        );

        self::assertSame(CveStatus::Vulnerable, $report->cves['CVE-1']);
        self::assertSame(CveStatus::Vulnerable, $report->cves['CVE-2']);
    }

    public function test_it_marks_a_cve_unknown_when_a_patch_fixing_it_is_unknown_and_none_is_missing(): void
    {
        $report = $this->analyze(
            ['A' => ['cves' => ['CVE-1']], 'B' => ['cves' => ['CVE-1']]],
            ['A' => DetectionState::Unknown, 'B' => DetectionState::Applied],
            [],
        );

        self::assertSame(CveStatus::Unknown, $report->cves['CVE-1']);
    }

    public function test_it_aggregates_cve_status_over_protected_and_not_applicable_patches_with_sorted_keys(): void
    {
        $report = $this->analyze(
            [
                'A' => ['cves' => ['CVE-9']],
                'B' => ['cves' => ['CVE-2']],
                'C' => ['cves' => ['CVE-3']],
                'D' => ['cves' => ['CVE-4']],
                'E' => ['cves' => ['CVE-5']],
            ],
            [
                'A' => DetectionState::Applied,
                'B' => DetectionState::NotApplied,
                'C' => DetectionState::NotApplicable,
                'D' => DetectionState::NotApplied,
                'E' => DetectionState::Applied,
            ],
            ['B', 'D', 'D', 'E', 'E'],
        );

        self::assertSame(['CVE-2', 'CVE-3', 'CVE-4', 'CVE-5', 'CVE-9'], array_keys($report->cves));
        self::assertSame(CveStatus::Protected, $report->cves['CVE-2']);
        self::assertSame(CveStatus::NotApplicable, $report->cves['CVE-3']);
        self::assertSame(CveStatus::Vulnerable, $report->cves['CVE-4']);
        self::assertSame(CveStatus::Protected, $report->cves['CVE-5']);
        self::assertSame(CveStatus::Protected, $report->cves['CVE-9']);
    }

    public function test_it_renders_cve_status_in_upper_case(): void
    {
        self::assertSame('PROTECTED', CveStatus::Protected->value);
        self::assertSame('NOT_APPLICABLE', CveStatus::NotApplicable->value);
    }

    public function test_it_decides_findings_from_the_configured_fail_on_states(): void
    {
        $report = $this->analyze(
            ['A' => [], 'B' => []],
            ['A' => DetectionState::Applied, 'B' => DetectionState::NotApplied],
            [],
        );

        self::assertTrue($report->hasFindings([PatchState::Missing]));
        self::assertFalse($report->hasFindings([PatchState::Duplicate, PatchState::Misordered]));
        self::assertFalse($report->hasFindings([]));
    }

    public function test_it_counts_patches_per_state_including_zero_counts(): void
    {
        $report = $this->analyze(
            ['A' => [], 'B' => []],
            ['A' => DetectionState::Applied, 'B' => DetectionState::NotApplied],
            [],
        );

        $summary = $report->summary();

        self::assertSame(1, $summary->count(PatchState::Applied));
        self::assertSame(1, $summary->count(PatchState::Missing));
        self::assertSame(0, $summary->count(PatchState::Duplicate));
        self::assertSame(
            ['applied', 'queued', 'duplicate', 'misordered', 'missing', 'entitled', 'unknown', 'not_applicable'],
            array_keys($summary->counts()),
        );
    }

    public function test_it_keeps_every_patch_of_a_dependency_cycle_in_the_report_and_warns(): void
    {
        $report = $this->analyze(
            ['A' => [], 'X' => ['requires' => ['Y']], 'Y' => ['requires' => ['X']], 'B' => ['requires' => ['A']]],
            [
                'A' => DetectionState::Applied,
                'B' => DetectionState::Applied,
                'X' => DetectionState::Applied,
                'Y' => DetectionState::Applied,
            ],
            [],
            warnings: ['earlier warning'],
        );

        self::assertSame(['A', 'B', 'X', 'Y'], array_map(
            static fn (PatchReport $patch): string => $patch->patch->id,
            $report->patches,
        ));
        self::assertCount(2, $report->warnings);
        self::assertSame('earlier warning', $report->warnings[0]);
        self::assertStringContainsString('X, Y', $report->warnings[1]);
    }

    public function test_it_carries_the_installation_facts_and_the_queue_into_the_report(): void
    {
        $report = $this->analyze(['A' => []], ['A' => DetectionState::Applied], ['A', 'Z']);

        self::assertSame('2.4.9', $report->baseVersion);
        self::assertSame(Edition::OpenSource, $report->edition);
        self::assertSame(['ce' => '2.4.9'], $report->components->versions);
        self::assertSame(RegistrySource::Cache, $report->registrySource);
        self::assertCount(2, $report->queue()->entries);
    }

    public function test_it_treats_a_patch_without_a_detection_result_as_unknown(): void
    {
        $report = $this->analyze(['A' => []], [], []);

        self::assertSame(PatchState::Unknown, $report->patches[0]->state);
    }

    /**
     * @param list<string> $requires
     */
    private function registryPatch(string $id, array $requires): RegistryPatch
    {
        return new RegistryPatch(
            $id,
            ['2.4.9'],
            'ce',
            $requires,
            [],
            new \DateTimeImmutable('2026-01-01'),
            $id . '.diff',
            'sha-' . $id,
        );
    }

    private function state(Report $report, string $id): PatchState
    {
        foreach ($report->patches as $patch) {
            if ($patch->patch->id === $id) {
                return $patch->state;
            }
        }
        self::fail('No patch ' . $id);
    }

    /**
     * @param array<string, array{requires?: list<string>, cves?: list<string>, entitlements?: list<string>}> $specs
     * @param array<string, DetectionState|DetectionResult> $detections
     * @param list<string> $queue patch ids in queue order (a patch id may repeat; "Z" matches no patch)
     * @param array<string, string> $packages
     * @param list<string> $warnings
     */
    private function analyze(
        array $specs,
        array $detections,
        array $queue,
        bool $includeEntitled = false,
        array $packages = [],
        array $warnings = [],
    ): Report {
        $patches = [];
        foreach ($specs as $id => $spec) {
            $patches[$id] = new RegistryPatch(
                $id,
                ['2.4.9'],
                'ce',
                $spec['requires'] ?? [],
                $spec['cves'] ?? [],
                new \DateTimeImmutable('2026-01-01'),
                $id . '.diff',
                'sha-' . $id,
                $spec['entitlements'] ?? [],
            );
        }
        $registry = new Registry('1', 'https://example.test', [], $patches);
        $results = [];
        foreach ($detections as $id => $detection) {
            $results[$id] = $detection instanceof DetectionResult ? $detection : DetectionResult::of($detection);
        }
        $entries = [];
        foreach ($queue as $index => $id) {
            $entries[] = new QueueEntry(
                $index + 1,
                QueueOrigin::Hotfix,
                null,
                'm2-hotfixes/' . $id . '.patch',
                'sha-' . $id,
            );
        }

        return (new Analyzer())->analyze(new AnalysisInput(
            new Installation('/store', $packages, [], Edition::OpenSource, '2.4.9', null, false),
            new InstalledComponents(['ce' => '2.4.9']),
            array_values($patches),
            new DependencyGraph($registry, array_keys($patches)),
            new DetectionResults($results),
            new PatchQueue($entries),
            RegistrySource::Cache,
            $includeEntitled,
            $warnings,
        ));
    }
}
