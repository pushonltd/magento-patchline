<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli\Output;

use Patchline\Cli\Output\RewindTextRenderer;
use Patchline\Cli\Output\Style;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use Patchline\Rewind\RewindOutcome;
use Patchline\Rewind\RewindResult;
use Patchline\Rewind\RewindStep;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RewindTextRenderer::class)]
#[UsesClass(Style::class)]
#[UsesClass(RewindResult::class)]
#[UsesClass(RewindStep::class)]
#[UsesClass(RewindOutcome::class)]
#[UsesClass(QueueEntry::class)]
final class RewindTextRendererTest extends TestCase
{
    /**
     * @return iterable<string, array{RewindOutcome, string}>
     */
    public static function outcomes(): iterable
    {
        yield 'reversed' => [RewindOutcome::Reversed, 'reversed'];
        yield 'would reverse' => [RewindOutcome::WouldReverse, 'would reverse'];
        yield 'not applied' => [RewindOutcome::NotApplied, 'not applied'];
        yield 'skipped' => [RewindOutcome::SkippedNotInstalled, 'skipped: target module(s) are not installed'];
        yield 'blocked' => [RewindOutcome::Blocked, 'blocked'];
    }

    #[DataProvider('outcomes')]
    public function test_it_prints_a_line_with_the_label_of_every_outcome(RewindOutcome $outcome, string $label): void
    {
        $result = new RewindResult([new RewindStep($this->entry(1, 'm2-hotfixes/a.patch'), $outcome, 'oops')], false);

        $out = $this->render($result);

        self::assertStringContainsString('  1. m2-hotfixes/a.patch: ' . $label . "\n", $out);
    }

    public function test_it_prints_the_title_of_a_cloud_patches_entry(): void
    {
        $step = new RewindStep($this->entry(1, 'vendor/x.patch', 'Patch X'), RewindOutcome::Reversed);
        $result = new RewindResult([$step], true);

        self::assertStringContainsString('  1. vendor/x.patch (Patch X): reversed', $this->render($result));
    }

    public function test_it_says_the_queue_is_empty(): void
    {
        $out = $this->render(new RewindResult([], false));

        self::assertStringContainsString('The patch queue is empty.', $out);
        self::assertStringContainsString('Nothing to rewind', $out);
    }

    public function test_it_counts_a_single_reversed_patch_in_the_singular(): void
    {
        $result = new RewindResult([new RewindStep($this->entry(1, 'a.patch'), RewindOutcome::Reversed)], true);

        self::assertStringContainsString('Reversed 1 patch. Run ece-patches apply next.', $this->render($result));
    }

    public function test_it_lists_the_entries_already_reversed_when_the_commit_failed_partway(): void
    {
        $result = new RewindResult([
            new RewindStep($this->entry(2, 'b.patch'), RewindOutcome::Reversed),
            new RewindStep(
                $this->entry(1, 'a.patch'),
                RewindOutcome::Blocked,
                "Hunk #1 FAILED\n1 out of 1 hunk FAILED",
            ),
        ], true);

        $out = $this->render($result);

        self::assertStringContainsString('Blocked at a.patch.', $out);
        self::assertStringContainsString("    Hunk #1 FAILED\n    1 out of 1 hunk FAILED\n", $out);
        self::assertStringContainsString("already reversed:\n  - b.patch\n", $out);
        self::assertStringContainsString('Reinstall vendor/magento', $out);
        self::assertStringNotContainsString('Nothing was changed.', $out);
    }

    public function test_it_prints_the_queue_warnings(): void
    {
        $out = (new RewindTextRenderer(Style::disabled()))->render(new RewindResult([], false), ['Careful.'], false);

        self::assertStringContainsString("Warnings\n  - Careful.\n", $out);
    }

    public function test_it_colours_the_outcome_only_when_the_style_is_enabled(): void
    {
        $result = new RewindResult([new RewindStep($this->entry(1, 'a.patch'), RewindOutcome::Reversed)], true);

        $out = (new RewindTextRenderer(Style::enabled()))->render($result, [], false);

        self::assertStringContainsString("\033[32mreversed\033[0m", $out);
    }

    private function render(RewindResult $result): string
    {
        return (new RewindTextRenderer(Style::disabled()))->render($result, [], false);
    }

    private function entry(int $position, string $path, ?string $title = null): QueueEntry
    {
        return new QueueEntry($position, QueueOrigin::Hotfix, $title, $path, 'sha');
    }
}
