<?php

declare(strict_types=1);

namespace Patchline\Cli\Output;

use Patchline\Rewind\RewindOutcome;
use Patchline\Rewind\RewindResult;
use Patchline\Rewind\RewindStep;

/** Plain text report of a rewind: one line per queue entry (newest first), then what to do next. */
final class RewindTextRenderer
{
    private const BLOCKED_GUIDANCE = 'A patch not in the queue (for example a deleted m2-hotfixes file) or a'
        . ' local change touches the same lines. Restore the missing hotfix files (patchline sync)'
        . ' or reinstall vendor/magento, then run patchline rewind again.';

    public function __construct(private readonly Style $style)
    {
    }

    /**
     * @param list<string> $warnings
     */
    public function render(RewindResult $result, array $warnings, bool $dryRun): string
    {
        $out = $result->steps === []
            ? "The patch queue is empty.\n"
            : "Rewinding the patch queue, newest entry first\n";
        foreach ($result->steps as $step) {
            $out .= $this->line($step);
        }
        $out .= $this->blocked($result);
        $out .= $this->warnings($warnings);

        return $out . "\n" . $this->summary($result, $dryRun);
    }

    private function line(RewindStep $step): string
    {
        $entry = $step->entry;
        $label = $entry->title === null ? $entry->path : sprintf('%s (%s)', $entry->path, $entry->title);

        return sprintf("  %d. %s: %s\n", $entry->position, $label, $this->outcome($step->outcome));
    }

    private function outcome(RewindOutcome $outcome): string
    {
        return match ($outcome) {
            RewindOutcome::Reversed => $this->style->green($outcome->label()),
            RewindOutcome::WouldReverse => $this->style->yellow($outcome->label()),
            RewindOutcome::Blocked => $this->style->red($outcome->label()),
            default => $this->style->dim($outcome->label()),
        };
    }

    private function blocked(RewindResult $result): string
    {
        $step = $result->blockedStep();
        if ($step === null) {
            return '';
        }

        $out = sprintf("\n%s\n", $this->style->red('Blocked at ' . $step->entry->path . '.'));
        foreach (explode("\n", trim($step->output)) as $line) {
            $out .= '    ' . $line . "\n";
        }

        return $out . self::BLOCKED_GUIDANCE . "\n";
    }

    /**
     * @param list<string> $warnings
     */
    private function warnings(array $warnings): string
    {
        if ($warnings === []) {
            return '';
        }

        $out = "\nWarnings\n";
        foreach ($warnings as $warning) {
            $out .= '  - ' . $warning . "\n";
        }

        return $out;
    }

    private function summary(RewindResult $result, bool $dryRun): string
    {
        $count = $this->count($result, $dryRun ? RewindOutcome::WouldReverse : RewindOutcome::Reversed);
        if ($result->blockedStep() !== null) {
            return $this->blockedSummary($result);
        }
        if ($count === 0) {
            return "Nothing to rewind: no patch of the queue is applied in vendor/. Nothing was changed.\n";
        }
        if ($dryRun) {
            return sprintf("Dry run: %d %s would be reversed. Nothing was changed.\n", $count, $this->noun($count));
        }

        return sprintf("Reversed %d %s. Run ece-patches apply next.\n", $count, $this->noun($count));
    }

    private function blockedSummary(RewindResult $result): string
    {
        if (!$result->changed) {
            return "Rewind failed. Nothing was changed.\n";
        }

        $out = "Rewind stopped halfway. These entries were already reversed:\n";
        foreach ($result->steps as $step) {
            if ($step->outcome !== RewindOutcome::Reversed) {
                continue;
            }

            $out .= '  - ' . $step->entry->path . "\n";
        }

        return $out . "Reinstall vendor/magento (composer install after removing it) to start from a clean state.\n";
    }

    private function count(RewindResult $result, RewindOutcome $outcome): int
    {
        return count(array_filter(
            $result->steps,
            static fn (RewindStep $step): bool => $step->outcome === $outcome,
        ));
    }

    private function noun(int $count): string
    {
        return $count === 1 ? 'patch' : 'patches';
    }
}
