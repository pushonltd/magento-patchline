<?php

declare(strict_types=1);

namespace Patchline\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\CrossCheck\CrossCheckResult;
use Patchline\CrossCheck\Disagreement;
use Patchline\Sync\ActionStatus;
use Patchline\Sync\ExecutionResult;
use Patchline\Verify\ReplayOutcome;
use Patchline\Verify\VerifyResult;

/**
 * GitHub Actions workflow commands on stdout, plus a Markdown job summary as the
 * {@see self::STEP_SUMMARY} artefact (the caller appends it to the file named by $GITHUB_STEP_SUMMARY).
 */
final class GithubFormatter implements Formatter
{
    /** Artefact key of the Markdown job summary. */
    public const STEP_SUMMARY = 'github-step-summary';

    public function format(
        Report $report,
        ?ExecutionResult $sync = null,
        ?VerifyResult $verify = null,
        bool $dryRun = false,
        ?CrossCheckResult $crossCheck = null,
    ): FormattedOutput {
        $lines = [];
        foreach ($report->patches as $patch) {
            $line = $this->patchAnnotation($patch);
            if ($line !== null) {
                $lines[] = $line;
            }
            foreach ($patch->detection->warnings as $warning) {
                $lines[] = self::command('warning', [], $patch->patch->id . ': ' . $warning);
            }
        }
        foreach ([...$report->warnings, ...$report->queue()->warnings] as $warning) {
            $lines[] = self::command('warning', [], $warning);
        }
        if ($sync !== null) {
            array_push($lines, ...$this->syncAnnotations($sync));
        }
        if ($verify !== null) {
            array_push($lines, ...$this->verifyAnnotations($verify));
        }
        if ($crossCheck !== null) {
            array_push($lines, ...$this->crossCheckAnnotations($crossCheck));
        }
        $lines[] = self::command('notice', [], $this->summaryLine($report));

        return new FormattedOutput(
            implode("\n", $lines) . "\n",
            [self::STEP_SUMMARY => $this->markdown($report, $sync, $verify, $dryRun, $crossCheck)],
        );
    }

    private function patchAnnotation(PatchReport $patch): ?string
    {
        $id = $patch->patch->id;
        $title = ['title' => $patch->state->value];

        return match ($patch->state) {
            PatchState::Missing => self::command(
                'error',
                $title,
                $id . ' is not applied and not queued. Run patchline sync to download it.',
            ),
            PatchState::Duplicate => self::command(
                'error',
                $title,
                $id . ' is queued more than once. Run patchline sync --prune to remove the extra copies.',
            ),
            PatchState::Misordered => self::command(
                'error',
                $title,
                $id . ' is queued before a patch it requires. Run patchline sync --adopt to fix the file names.',
            ),
            PatchState::Entitled => self::command(
                'warning',
                $title,
                $id . ' needs an entitlement this store lacks; patchline does not install it.',
            ),
            PatchState::Unknown => self::command(
                'warning',
                $title,
                $id . ' could not be checked against the installed code.',
            ),
            PatchState::Applied, PatchState::Queued, PatchState::NotApplicable => null,
        };
    }

    /** @return list<string> */
    private function syncAnnotations(ExecutionResult $sync): array
    {
        $lines = [];
        foreach ($sync->outcomes as $outcome) {
            $message = $outcome->action->describe();
            if ($outcome->message !== '' && $outcome->message !== $message) {
                $message .= ': ' . $outcome->message;
            }
            if ($outcome->status === ActionStatus::Failed) {
                $lines[] = self::command('error', ['title' => 'sync'], $message);
            } elseif ($outcome->status === ActionStatus::Warning) {
                $lines[] = self::command('warning', ['title' => 'sync'], $message);
            }
        }

        return $lines;
    }

    /** @return list<string> */
    private function verifyAnnotations(VerifyResult $verify): array
    {
        $lines = [];
        foreach ($verify->steps as $step) {
            $properties = ['title' => 'verify', 'file' => $step->entry->path];
            if ($step->outcome === ReplayOutcome::Failed) {
                $lines[] = self::command(
                    'error',
                    $properties,
                    $step->entry->path . ' would fail: ' . trim($step->output),
                );
            } elseif ($step->outcome === ReplayOutcome::AlreadyAppliedIgnoringPatchStatus) {
                $lines[] = self::command(
                    'warning',
                    $properties,
                    $step->entry->path . ' is already applied once vendor/bin/patch-status is ignored.',
                );
            }
        }

        return $lines;
    }

    private function summaryLine(Report $report): string
    {
        $parts = [];
        foreach ($report->summary()->counts() as $state => $count) {
            if ($count <= 0) {
                continue;
            }

            $parts[] = $count . ' ' . $state;
        }

        return 'Summary: ' . ($parts === [] ? 'no patches' : implode(', ', $parts));
    }

    private function markdown(
        Report $report,
        ?ExecutionResult $sync,
        ?VerifyResult $verify,
        bool $dryRun,
        ?CrossCheckResult $crossCheck,
    ): string {
        $out = "## Patchline\n\n" . $this->summaryLine($report) . "\n\n";
        if ($report->patches !== []) {
            $out .= "| Patch | Area | State | CVEs |\n| --- | --- | --- | --- |\n";
            foreach ($report->patches as $patch) {
                $out .= sprintf(
                    "| %s | %s | %s | %s |\n",
                    self::cell($patch->patch->id),
                    self::cell($patch->patch->area),
                    $patch->state->value,
                    self::cell(implode(', ', $patch->patch->cves)),
                );
            }
            $out .= "\n";
        }
        if ($sync !== null) {
            $out .= '### Sync' . ($dryRun ? " (dry run)\n\n" : "\n\n");
            foreach ($sync->outcomes as $outcome) {
                $out .= sprintf("- **%s** %s\n", $outcome->status->value, self::cell($outcome->action->describe()));
            }
            $out .= "\n";
        }
        if ($verify !== null) {
            $failed = $verify->failedStep();
            $out .= "### Verify\n\n" . ($failed === null
                ? "ece-patches apply should succeed.\n"
                : 'ece-patches apply would stop at `' . $failed->entry->path . "`.\n");
        }
        if ($crossCheck !== null) {
            $out .= "### Cross-check\n\n" . $this->crossCheckMarkdown($crossCheck);
        }

        return $out;
    }

    private function crossCheckMarkdown(CrossCheckResult $crossCheck): string
    {
        if (!$crossCheck->ran()) {
            return self::cell((string) $crossCheck->warning) . "\n";
        }
        if (!$crossCheck->hasDisagreements()) {
            return "Adobe patch-status agrees with Patchline.\n";
        }

        $out = '';
        foreach ($crossCheck->disagreements as $disagreement) {
            $out .= '- ' . self::cell($disagreement->describe()) . "\n";
        }

        return $out;
    }

    /** @return list<string> */
    private function crossCheckAnnotations(CrossCheckResult $crossCheck): array
    {
        $properties = ['title' => 'cross-check'];
        if (!$crossCheck->ran()) {
            return [self::command('warning', $properties, (string) $crossCheck->warning)];
        }
        if (!$crossCheck->hasDisagreements()) {
            return [self::command('notice', $properties, 'Adobe patch-status agrees with Patchline.')];
        }

        return array_map(
            static fn (Disagreement $d): string => self::command('warning', $properties, $d->describe()),
            $crossCheck->disagreements,
        );
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $text);
    }

    /** @param array<string, string> $properties */
    private static function command(string $name, array $properties, string $message): string
    {
        $props = [];
        foreach ($properties as $key => $value) {
            $props[] = $key . '=' . self::escape($value, true);
        }

        return '::' . $name . ($props === [] ? '' : ' ' . implode(',', $props)) . '::' . self::escape($message, false);
    }

    private static function escape(string $value, bool $property): string
    {
        $search = ['%', "\r", "\n"];
        $replace = ['%25', '%0D', '%0A'];
        if ($property) {
            $search = [...$search, ':', ','];
            $replace = [...$replace, '%3A', '%2C'];
        }

        return str_replace($search, $replace, $value);
    }
}
