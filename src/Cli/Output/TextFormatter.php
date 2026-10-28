<?php

declare(strict_types=1);

namespace Patchline\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\CrossCheck\CrossCheckResult;
use Patchline\Installation\Edition;
use Patchline\Queue\QueueEntry;
use Patchline\Registry\RegistrySource;
use Patchline\Sync\ActionStatus;
use Patchline\Sync\ExecutionResult;
use Patchline\Verify\ReplayStep;
use Patchline\Verify\VerifyResult;

/** Human-readable output. Prints only report data, never credentials. */
final class TextFormatter implements Formatter
{
    public function __construct(private readonly Style $style)
    {
    }

    public function format(
        Report $report,
        ?ExecutionResult $sync = null,
        ?VerifyResult $verify = null,
        bool $dryRun = false,
        ?CrossCheckResult $crossCheck = null,
    ): FormattedOutput {
        $sections = [$this->header($report), $this->patchLines($report)];
        if ($sync !== null) {
            $sections[] = $this->syncSection($sync, $dryRun);
        }
        if ($verify !== null) {
            $sections[] = $this->verifySection($verify);
        }
        if ($crossCheck !== null) {
            $sections[] = $this->crossCheckSection($crossCheck);
        }
        $warnings = $this->warningsSection($report);
        if ($warnings !== '') {
            $sections[] = $warnings;
        }
        $sections[] = $this->summary($report);

        return new FormattedOutput(implode("\n", $sections));
    }

    private function header(Report $report): string
    {
        $edition = $report->edition === Edition::Commerce ? 'Adobe Commerce' : 'Magento Open Source';
        $components = [];
        foreach ($report->components->versions as $area => $version) {
            $components[] = $area . ' ' . $version;
        }

        return $this->style->bold(sprintf('%s %s', $edition, $report->baseVersion)) . "\n"
            . sprintf("Registry: %s\n", self::sourceLabel($report->registrySource))
            . sprintf("Components: %s\n", $components === [] ? 'none' : implode(', ', $components));
    }

    private function patchLines(Report $report): string
    {
        if ($report->patches === []) {
            return "No patches apply to this store.\n";
        }

        $areaWidth = max(array_map(static fn (PatchReport $p): int => strlen($p->patch->area), $report->patches));
        $idWidth = max(array_map(static fn (PatchReport $p): int => strlen($p->patch->id), $report->patches));
        $stateWidth = max(array_map(static fn (PatchReport $p): int => strlen($p->state->value), $report->patches));

        $lines = '';
        foreach ($report->patches as $patch) {
            $cells = [
                $patch->patch->released->format('Y-m-d'),
                str_pad($patch->patch->area, $areaWidth),
                str_pad($patch->patch->id, $idWidth),
                $this->colourState($patch->state, str_pad($patch->state->value, $stateWidth)),
            ];
            $locations = implode(', ', array_map(static fn (QueueEntry $e): string => $e->path, $patch->queueEntries));
            if ($locations !== '') {
                $cells[] = $locations;
            }
            $cves = count($patch->patch->cves);
            if ($cves > 0) {
                $cells[] = $cves . ($cves === 1 ? ' CVE' : ' CVEs');
            }
            $lines .= rtrim(implode('  ', $cells)) . "\n";
        }

        return $lines;
    }

    private function colourState(PatchState $state, string $text): string
    {
        return match ($state) {
            PatchState::Applied, PatchState::Queued => $this->style->green($text),
            PatchState::Duplicate, PatchState::Misordered, PatchState::Missing => $this->style->red($text),
            PatchState::Entitled, PatchState::Unknown => $this->style->yellow($text),
            PatchState::NotApplicable => $this->style->dim($text),
        };
    }

    private function syncSection(ExecutionResult $sync, bool $dryRun): string
    {
        $out = $this->style->bold($dryRun ? 'Sync (dry run, nothing changed)' : 'Sync') . "\n";
        if ($sync->outcomes === []) {
            return $out . "  Nothing to do.\n";
        }
        foreach ($sync->outcomes as $outcome) {
            $label = $this->colourStatus($outcome->status, '[' . $outcome->status->value . ']');
            $out .= sprintf("  %s %s\n", $label, $outcome->action->describe());
            if ($outcome->message === '' || $outcome->message === $outcome->action->describe()) {
                continue;
            }

            $out .= '    ' . $outcome->message . "\n";
        }

        return $out;
    }

    private function colourStatus(ActionStatus $status, string $text): string
    {
        return match ($status) {
            ActionStatus::Done => $this->style->green($text),
            ActionStatus::Failed => $this->style->red($text),
            ActionStatus::Warning => $this->style->yellow($text),
            ActionStatus::Skipped, ActionStatus::Planned => $this->style->dim($text),
        };
    }

    private function verifySection(VerifyResult $verify): string
    {
        $out = $this->style->bold('Verify (replay of the patch queue)') . "\n";
        foreach ($verify->steps as $step) {
            $out .= sprintf("  %d. %s: %s\n", $step->entry->position, $step->entry->path, $step->outcome->label());
        }
        $failed = $verify->failedStep();
        if ($failed !== null) {
            $out .= $this->failedStep($failed);
        }
        foreach ($verify->notes as $note) {
            $out .= '  Note: ' . $note . "\n";
        }

        if ($failed === null) {
            return $out . $this->style->green('Verify passed: ece-patches apply should succeed.') . "\n";
        }

        return $out . $this->style->red('Verify failed: ece-patches apply would stop at ' . $failed->entry->path . '.')
            . "\n";
    }

    private function failedStep(ReplayStep $step): string
    {
        $out = '  Patch output for ' . $step->entry->path . ":\n";
        foreach (explode("\n", rtrim($step->output)) as $line) {
            $out .= '    ' . $line . "\n";
        }

        return $out;
    }

    private function crossCheckSection(CrossCheckResult $crossCheck): string
    {
        $out = $this->style->bold('Cross-check with Adobe patch-status') . "\n";
        if (!$crossCheck->ran()) {
            return $out . '  ' . $this->style->yellow((string) $crossCheck->warning) . "\n";
        }
        if (!$crossCheck->hasDisagreements()) {
            return $out . '  ' . $this->style->green(sprintf(
                'Both tools agree on %d %s.',
                $crossCheck->compared,
                $crossCheck->compared === 1 ? 'patch' : 'patches',
            )) . "\n";
        }

        $count = count($crossCheck->disagreements);
        $out .= '  ' . $this->style->red($count . ($count === 1 ? ' disagreement:' : ' disagreements:')) . "\n";
        foreach ($crossCheck->disagreements as $disagreement) {
            $out .= '  - ' . $disagreement->describe() . "\n";
        }

        return $out;
    }

    private function warningsSection(Report $report): string
    {
        $warnings = [...$report->warnings, ...$report->queue()->warnings];
        foreach ($report->patches as $patch) {
            foreach ($patch->detection->warnings as $warning) {
                $warnings[] = $patch->patch->id . ': ' . $warning;
            }
        }
        if ($warnings === []) {
            return '';
        }

        $out = $this->style->yellow('Warnings') . "\n";
        foreach ($warnings as $warning) {
            $out .= '  - ' . $warning . "\n";
        }

        return $out;
    }

    private function summary(Report $report): string
    {
        $summary = $report->summary();
        $parts = [];
        foreach ($summary->counts() as $state => $count) {
            if ($count <= 0) {
                continue;
            }

            $parts[] = $count . ' ' . $state;
        }

        return 'Summary: ' . ($parts === [] ? 'no patches' : implode(', ', $parts)) . "\n"
            . 'Next: ' . $this->nextStep($report) . "\n";
    }

    private function nextStep(Report $report): string
    {
        $summary = $report->summary();
        if ($summary->count(PatchState::Missing) > 0) {
            return 'run patchline sync to download the missing patches.';
        }
        $misordered = $summary->count(PatchState::Misordered) > 0;
        $duplicate = $summary->count(PatchState::Duplicate) > 0;
        if ($misordered && $duplicate) {
            return 'run patchline sync --adopt --prune to rename hand-copied patch files into release order'
                . ' and remove duplicate m2-hotfixes copies.';
        }
        if ($misordered) {
            return 'run patchline sync --adopt to rename hand-copied patch files into release order.';
        }
        if ($duplicate) {
            return 'run patchline sync --prune to remove duplicate m2-hotfixes copies.';
        }
        if ($summary->count(PatchState::Queued) > 0) {
            return 'run patchline verify to check that ece-patches apply will accept the queued patches.';
        }

        return 'nothing to do.';
    }

    private static function sourceLabel(RegistrySource $source): string
    {
        return match ($source) {
            RegistrySource::Remote => 'remote',
            RegistrySource::Cache => 'cache',
            RegistrySource::StaleCache => 'stale cache',
            RegistrySource::File => 'file',
        };
    }
}
