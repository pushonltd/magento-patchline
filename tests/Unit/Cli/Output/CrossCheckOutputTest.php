<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\Report;
use Patchline\Analysis\ReportSummary;
use Patchline\Cli\Output\FormattedOutput;
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
use Patchline\Tests\Support\ReportFixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonFormatter::class)]
#[CoversClass(TextFormatter::class)]
#[CoversClass(GithubFormatter::class)]
#[CoversClass(JunitFormatter::class)]
#[UsesClass(PatchReport::class)]
#[UsesClass(Report::class)]
#[UsesClass(ReportSummary::class)]
#[UsesClass(FormattedOutput::class)]
#[UsesClass(Style::class)]
#[UsesClass(CrossCheckResult::class)]
#[UsesClass(Disagreement::class)]
#[UsesClass(DetectionResult::class)]
#[UsesClass(PatchQueue::class)]
#[UsesClass(QueueEntry::class)]
#[UsesClass(InstalledComponents::class)]
#[UsesClass(RegistryPatch::class)]
final class CrossCheckOutputTest extends TestCase
{
    public function test_it_adds_a_cross_check_block_to_json_only_when_a_result_is_given(): void
    {
        $without = $this->decode((new JsonFormatter())->format(ReportFixtures::mixed())->document);
        $agreed = $this->decode(
            (new JsonFormatter())->format(ReportFixtures::mixed(), crossCheck: CrossCheckResult::compared(3, []))
                ->document,
        );
        $disagreed = $this->decode((new JsonFormatter())->format(
            ReportFixtures::mixed(),
            crossCheck: CrossCheckResult::compared(3, [new Disagreement('249-X-CE', 'applied', 'unknown')]),
        )->document);
        $skipped = $this->decode((new JsonFormatter())->format(
            ReportFixtures::mixed(),
            crossCheck: CrossCheckResult::skipped('Adobe patch-status not installed here; cross-check skipped'),
        )->document);

        self::assertArrayNotHasKey('cross_check', $without);
        self::assertSame(['status' => 'agreed', 'compared' => 3, 'disagreements' => []], $agreed['cross_check']);
        self::assertSame(
            [
                'status' => 'disagreed',
                'compared' => 3,
                'disagreements' => [['id' => '249-X-CE', 'patchline' => 'applied', 'adobe' => 'unknown']],
            ],
            $disagreed['cross_check'],
        );
        self::assertSame(
            [
                'status' => 'skipped',
                'compared' => 0,
                'disagreements' => [],
                'warning' => 'Adobe patch-status not installed here; cross-check skipped',
            ],
            $skipped['cross_check'],
        );
    }

    public function test_it_adds_a_cross_check_section_to_text(): void
    {
        $text = new TextFormatter(Style::disabled());
        $plain = $text->format(ReportFixtures::mixed())->document;

        $agreed = $text->format(ReportFixtures::mixed(), crossCheck: CrossCheckResult::compared(3, []))->document;
        $disagreed = $text->format(
            ReportFixtures::mixed(),
            crossCheck: CrossCheckResult::compared(3, [new Disagreement('249-X-CE', 'applied', 'unknown')]),
        )->document;
        $skipped = $text->format(ReportFixtures::mixed(), crossCheck: CrossCheckResult::skipped('nope'))->document;

        self::assertStringNotContainsString('Cross-check', $plain);
        self::assertStringContainsString(
            "Cross-check with Adobe patch-status\n  Both tools agree on 3 patches.\n",
            $agreed,
        );
        self::assertStringContainsString(
            "Cross-check with Adobe patch-status\n  1 disagreement:\n"
            . "  - 249-X-CE: Patchline detects applied, Adobe patch-status reports unknown.\n",
            $disagreed,
        );
        self::assertStringContainsString("Cross-check with Adobe patch-status\n  nope\n", $skipped);
    }

    public function test_it_adds_cross_check_annotations_to_github_output(): void
    {
        $github = new GithubFormatter();

        $plain = $github->format(ReportFixtures::mixed());
        $disagreed = $github->format(
            ReportFixtures::mixed(),
            crossCheck: CrossCheckResult::compared(3, [new Disagreement('249-X-CE', 'applied', 'unknown')]),
        );
        $skipped = $github->format(ReportFixtures::mixed(), crossCheck: CrossCheckResult::skipped('nope'));
        $agreed = $github->format(ReportFixtures::mixed(), crossCheck: CrossCheckResult::compared(3, []));

        self::assertStringNotContainsString('cross-check', $plain->document);
        self::assertStringContainsString(
            '::warning title=cross-check::249-X-CE: Patchline detects applied, Adobe patch-status reports unknown.',
            $disagreed->document,
        );
        self::assertStringContainsString('::warning title=cross-check::nope', $skipped->document);
        self::assertStringContainsString('::notice title=cross-check::', $agreed->document);
        self::assertStringContainsString(
            '### Cross-check',
            $disagreed->artefacts[GithubFormatter::STEP_SUMMARY] ?? '',
        );
    }

    public function test_it_adds_a_cross_check_suite_to_junit(): void
    {
        $junit = new JunitFormatter();

        $plain = $junit->format(ReportFixtures::mixed())->document;
        $agreed = $junit->format(ReportFixtures::mixed(), crossCheck: CrossCheckResult::compared(3, []))->document;
        $disagreed = $junit->format(
            ReportFixtures::mixed(),
            crossCheck: CrossCheckResult::compared(3, [new Disagreement('249-X-CE', 'applied', 'unknown')]),
        )->document;
        $skipped = $junit->format(ReportFixtures::mixed(), crossCheck: CrossCheckResult::skipped('nope'))->document;

        self::assertStringNotContainsString('cross-check', $plain);
        self::assertStringContainsString(
            '<testsuite name="patchline-cross-check" tests="1" failures="0" skipped="0">',
            $agreed,
        );
        self::assertStringContainsString(
            '<testsuite name="patchline-cross-check" tests="1" failures="1" skipped="0">',
            $disagreed,
        );
        self::assertStringContainsString('name="249-X-CE"', $disagreed);
        self::assertStringContainsString(
            '<testsuite name="patchline-cross-check" tests="1" failures="0" skipped="1">',
            $skipped,
        );
    }

    /** @return array<mixed, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
