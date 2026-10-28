<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\Report;
use Patchline\Analysis\ReportSummary;
use Patchline\Cli\Output\FormattedOutput;
use Patchline\Cli\Output\Formatter;
use Patchline\Cli\Output\GithubFormatter;
use Patchline\Cli\Output\JsonFormatter;
use Patchline\Cli\Output\JunitFormatter;
use Patchline\Cli\Output\Style;
use Patchline\Cli\Output\TextFormatter;
use Patchline\CrossCheck\CrossCheckResult;
use Patchline\CrossCheck\Disagreement;
use Patchline\Detection\DetectionResult;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\RegistryPatch;
use Patchline\Registry\RegistrySource;
use Patchline\Sync\Action\Download;
use Patchline\Sync\Action\Remove;
use Patchline\Sync\Action\Rename;
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

/**
 * Every formatter renders the same four rich scenarios (every patch state, every sync status, every replay
 * outcome, every registry source and every cross-check result); the output is compared byte for byte.
 */
#[CoversClass(TextFormatter::class)]
#[CoversClass(JsonFormatter::class)]
#[CoversClass(GithubFormatter::class)]
#[CoversClass(JunitFormatter::class)]
#[CoversClass(Style::class)]
#[UsesClass(FormattedOutput::class)]
#[UsesClass(PatchReport::class)]
#[UsesClass(Report::class)]
#[UsesClass(ReportSummary::class)]
#[UsesClass(CrossCheckResult::class)]
#[UsesClass(Disagreement::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(RegistryPatch::class)]
#[UsesClass(ActionOutcome::class)]
#[UsesClass(Download::class)]
#[UsesClass(Rename::class)]
#[UsesClass(Remove::class)]
#[UsesClass(Warn::class)]
#[UsesClass(ExecutionResult::class)]
#[UsesClass(ReplayOutcome::class)]
#[UsesClass(ReplayStep::class)]
#[UsesClass(VerifyResult::class)]
final class FormatterGoldenTest extends TestCase
{
    #[DataProvider('combinations')]
    public function test_it_renders_the_scenario_exactly_as_the_golden_file(
        string $formatter,
        string $scenario,
        string $extension,
    ): void {
        $output = $this->formatter($formatter)->format(...self::scenario($scenario));

        self::assertSame(
            Golden::expected(sprintf('%s-%s.%s', $formatter, $scenario, $extension), $output->document),
            $output->document,
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function combinations(): iterable
    {
        $extensions = ['text' => 'txt', 'json' => 'json', 'github' => 'txt', 'junit' => 'xml'];
        foreach ($extensions as $formatter => $extension) {
            foreach (['everything', 'dry-run', 'cache', 'file'] as $scenario) {
                yield $formatter . ' ' . $scenario => [$formatter, $scenario, $extension];
            }
        }
    }

    #[DataProvider('scenarios')]
    public function test_it_renders_the_github_job_summary_exactly_as_the_golden_file(string $scenario): void
    {
        $output = (new GithubFormatter())->format(...self::scenario($scenario));

        $summary = $output->artefacts[GithubFormatter::STEP_SUMMARY];
        self::assertSame(Golden::expected(sprintf('github-%s-summary.md', $scenario), $summary), $summary);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scenarios(): iterable
    {
        foreach (['everything', 'dry-run', 'cache', 'file'] as $scenario) {
            yield $scenario => [$scenario];
        }
    }

    public function test_it_renders_the_text_report_with_ansi_colours_when_the_style_is_enabled(): void
    {
        $output = (new TextFormatter(Style::enabled()))->format(...self::scenario('everything'));

        self::assertSame(
            Golden::expected('text-everything-ansi.txt', $output->document),
            $output->document,
        );
    }

    public function test_it_wraps_text_in_the_ansi_code_of_each_colour_only_when_enabled(): void
    {
        $style = Style::enabled();

        self::assertSame("\x1b[1mx\x1b[0m", $style->bold('x'));
        self::assertSame("\x1b[31mx\x1b[0m", $style->red('x'));
        self::assertSame("\x1b[32mx\x1b[0m", $style->green('x'));
        self::assertSame("\x1b[33mx\x1b[0m", $style->yellow('x'));
        self::assertSame("\x1b[2mx\x1b[0m", $style->dim('x'));
        self::assertSame('x', Style::disabled()->red('x'));
    }

    /**
     * @return array{Report, ?ExecutionResult, ?VerifyResult, bool, ?CrossCheckResult}
     */
    private static function scenario(string $name): array
    {
        $patch = ReportFixtures::patch('249-2026-10-001-CE', '2026-10-13');

        return match ($name) {
            'everything' => [
                ReportFixtures::everyState(RegistrySource::Remote),
                new ExecutionResult([
                    new ActionOutcome(new Download($patch, 'm2-hotfixes/a.patch'), ActionStatus::Done, 'downloaded'),
                    new ActionOutcome(new Download($patch, 'm2-hotfixes/b.patch'), ActionStatus::Failed, 'HTTP 500'),
                    new ActionOutcome(
                        new Rename('m2-hotfixes/c.patch', 'm2-hotfixes/d.patch', 'sha'),
                        ActionStatus::Skipped,
                        'target exists',
                    ),
                    new ActionOutcome(new Remove('m2-hotfixes/e.patch', 'sha'), ActionStatus::Done, ''),
                    new ActionOutcome(new Warn('Check the order.'), ActionStatus::Warning, 'Check the order.'),
                    new ActionOutcome(new Warn('Check, again | now.'), ActionStatus::Warning, 'Check, again | now.'),
                ]),
                new VerifyResult(self::allSteps(), ['patch 2.8 uses fuzz.']),
                false,
                CrossCheckResult::compared(3, [
                    new Disagreement('249-X-CE', 'applied', 'unknown'),
                    new Disagreement('249-Y-CE', 'not_applied', Disagreement::NOT_LISTED),
                ]),
            ],
            'dry-run' => [
                ReportFixtures::everyState(RegistrySource::StaleCache),
                new ExecutionResult([
                    new ActionOutcome(new Download($patch, 'm2-hotfixes/a.patch'), ActionStatus::Planned, ''),
                    new ActionOutcome(
                        new Rename('m2-hotfixes/c.patch', 'm2-hotfixes/d.patch', 'sha'),
                        ActionStatus::Planned,
                        '',
                    ),
                ]),
                new VerifyResult([
                    new ReplayStep(ReportFixtures::entry(1, 'm2-hotfixes/ok.patch'), ReplayOutcome::WouldApply),
                ]),
                true,
                CrossCheckResult::compared(1, []),
            ],
            'cache' => [
                ReportFixtures::everyState(RegistrySource::Cache),
                null,
                null,
                false,
                CrossCheckResult::skipped('Adobe patch-status not installed here; cross-check skipped'),
            ],
            'file' => [
                ReportFixtures::everyState(RegistrySource::File),
                new ExecutionResult([]),
                new VerifyResult([]),
                false,
                CrossCheckResult::compared(2, []),
            ],
            default => throw new \LogicException('Unknown scenario ' . $name),
        };
    }

    /**
     * @return list<ReplayStep>
     */
    private static function allSteps(): array
    {
        return [
            new ReplayStep(ReportFixtures::entry(1, 'm2-hotfixes/a.patch'), ReplayOutcome::WouldApply),
            new ReplayStep(ReportFixtures::entry(2, 'm2-hotfixes/b.patch'), ReplayOutcome::AlreadyApplied),
            new ReplayStep(
                ReportFixtures::entry(3, 'm2-hotfixes/c.patch'),
                ReplayOutcome::AlreadyAppliedIgnoringPatchStatus,
            ),
            new ReplayStep(ReportFixtures::entry(4, 'm2-hotfixes/d.patch'), ReplayOutcome::SkippedNotInstalled),
            new ReplayStep(
                ReportFixtures::entry(5, 'm2-hotfixes/e.patch'),
                ReplayOutcome::Failed,
                "patching file x.php\nHunk #1 FAILED at 3.\n100% & <broken>\n\n",
            ),
        ];
    }

    private function formatter(string $name): Formatter
    {
        return match ($name) {
            'text' => new TextFormatter(Style::disabled()),
            'json' => new JsonFormatter(),
            'github' => new GithubFormatter(),
            'junit' => new JunitFormatter(),
            default => throw new \LogicException('Unknown formatter ' . $name),
        };
    }
}
