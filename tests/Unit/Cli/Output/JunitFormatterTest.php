<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\Cli\Output\FormattedOutput;
use Patchline\Cli\Output\JunitFormatter;
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
use SimpleXMLElement;

#[CoversClass(JunitFormatter::class)]
#[UsesClass(PatchReport::class)]
#[UsesClass(Report::class)]
#[UsesClass(FormattedOutput::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(RegistryPatch::class)]
#[UsesClass(ActionOutcome::class)]
#[UsesClass(Download::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(ReplayOutcome::class)]
#[UsesClass(ReplayStep::class)]
#[UsesClass(VerifyResult::class)]
final class JunitFormatterTest extends TestCase
{
    public function test_it_renders_a_junit_testcase_per_patch_with_failures_for_failing_states(): void
    {
        $report = StateReports::of([
            '249-A-CE' => PatchState::Applied,
            '249-B-CE' => PatchState::Missing,
            '249-C-CE' => PatchState::Duplicate,
            '249-D-CE' => PatchState::Misordered,
        ]);

        $xml = self::parse((new JunitFormatter())->format($report)->document);

        $suite = $xml->testsuite[0];
        self::assertSame('patchline', (string) $suite['name']);
        self::assertSame('4', (string) $suite['tests']);
        self::assertSame('3', (string) $suite['failures']);
        self::assertCount(4, $suite->testcase);
        self::assertSame('CE', (string) $suite->testcase[1]['classname']);
        self::assertSame('249-B-CE', (string) $suite->testcase[1]['name']);
        self::assertCount(0, $suite->testcase[0]->failure);
        self::assertCount(1, $suite->testcase[1]->failure);
        self::assertStringContainsString('249-B-CE', (string) $suite->testcase[1]->failure['message']);
        self::assertCount(1, $suite->testcase[2]->failure);
        self::assertCount(1, $suite->testcase[3]->failure);
    }

    public function test_it_renders_entitled_and_unknown_patches_as_skipped_junit_testcases(): void
    {
        $report = StateReports::of(['249-A-CE' => PatchState::Entitled, '249-B-CE' => PatchState::Unknown]);

        $xml = self::parse((new JunitFormatter())->format($report)->document);

        $suite = $xml->testsuite[0];
        self::assertCount(1, $suite->testcase[0]->skipped);
        self::assertCount(1, $suite->testcase[1]->skipped);
        self::assertSame('2', (string) $suite['skipped']);
        self::assertSame('0', (string) $suite['failures']);
    }

    public function test_it_produces_junit_xml_that_parses_as_valid_xml(): void
    {
        $verify = new VerifyResult([
            new ReplayStep(
                ReportFixtures::entry(1, 'm2-hotfixes/<"a&b">.patch'),
                ReplayOutcome::Failed,
                "<<< 'quoted' & \"double\" \x01 >>>",
            ),
        ]);

        $document = (new JunitFormatter())->format(ReportFixtures::mixed(), null, $verify)->document;

        self::assertSame(Golden::expected('junit-verify.xml', $document), $document);
        $xml = self::parse($document);
        self::assertStringContainsString('<"a&b">', (string) $xml->testsuite[1]->testcase[0]['name']);
        $failure = (string) $xml->testsuite[1]->testcase[0]->failure;
        self::assertStringContainsString("<<< 'quoted' & \"double\"", $failure);
    }

    public function test_it_reports_the_failing_verify_step_as_a_junit_failure(): void
    {
        $verify = new VerifyResult([
            new ReplayStep(ReportFixtures::entry(1, 'm2-hotfixes/ok.patch'), ReplayOutcome::WouldApply),
            new ReplayStep(ReportFixtures::entry(2, 'm2-hotfixes/skip.patch'), ReplayOutcome::SkippedNotInstalled),
            new ReplayStep(ReportFixtures::entry(3, 'm2-hotfixes/bad.patch'), ReplayOutcome::Failed, 'Hunk #1 FAILED'),
        ]);

        $xml = self::parse((new JunitFormatter())->format(ReportFixtures::clean(), null, $verify)->document);

        $suite = $xml->testsuite[1];
        self::assertSame('patchline-verify', (string) $suite['name']);
        self::assertSame('1', (string) $suite['failures']);
        self::assertCount(3, $suite->testcase);
        self::assertCount(0, $suite->testcase[0]->failure);
        self::assertCount(1, $suite->testcase[1]->skipped);
        self::assertSame('Hunk #1 FAILED', (string) $suite->testcase[2]->failure);
        self::assertSame('m2-hotfixes/bad.patch would fail', (string) $suite->testcase[2]->failure['message']);
    }

    public function test_it_reports_failed_sync_actions_as_junit_failures(): void
    {
        $patch = ReportFixtures::patch('249-A-CE', '2026-10-13');
        $sync = new ExecutionResult([
            new ActionOutcome(new Download($patch, 'm2-hotfixes/a.patch'), ActionStatus::Failed, 'HTTP 500'),
            new ActionOutcome(new Download($patch, 'm2-hotfixes/b.patch'), ActionStatus::Done, 'Downloaded.'),
        ]);

        $xml = self::parse((new JunitFormatter())->format(ReportFixtures::clean(), $sync)->document);

        $suite = $xml->testsuite[1];
        self::assertSame('patchline-sync', (string) $suite['name']);
        self::assertSame('1', (string) $suite['failures']);
        self::assertSame('HTTP 500', (string) $suite->testcase[0]->failure['message']);
        self::assertCount(0, $suite->testcase[1]->failure);
    }

    private static function parse(string $document): SimpleXMLElement
    {
        $xml = simplexml_load_string($document);
        self::assertInstanceOf(SimpleXMLElement::class, $xml);

        return $xml;
    }
}
