<?php

declare(strict_types=1);

namespace Patchline\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\Report;
use Patchline\CrossCheck\CrossCheckResult;
use Patchline\CrossCheck\Disagreement;
use Patchline\Detection\DetectionState;
use Patchline\Queue\QueueEntry;
use Patchline\Registry\RegistrySource;
use Patchline\Sync\ExecutionResult;
use Patchline\Verify\ReplayOutcome;
use Patchline\Verify\ReplayStep;
use Patchline\Verify\VerifyResult;

/**
 * Machine-readable output, schema `patchline/report@1`. The first keys mirror Adobe's patch-status JSON.
 * Optional blocks (`sync`, `verify`) are left out entirely when the feature did not run.
 */
final class JsonFormatter implements Formatter
{
    public const SCHEMA = 'patchline/report@1';

    public function format(
        Report $report,
        ?ExecutionResult $sync = null,
        ?VerifyResult $verify = null,
        bool $dryRun = false,
        ?CrossCheckResult $crossCheck = null,
    ): FormattedOutput {
        $document = [
            'schema' => self::SCHEMA,
            'base_version' => $report->baseVersion,
            'edition' => $report->edition->value,
            'installed_components' => (object) $report->components->versions,
            'registry_source' => self::sourceValue($report->registrySource),
            'applied_patches' => $this->idsDetectedAs($report, DetectionState::Applied),
            'missing_patches' => $this->idsDetectedAs($report, DetectionState::NotApplied),
            'unknown_patches' => $this->idsDetectedAs($report, DetectionState::Unknown),
            'patches' => array_map($this->patch(...), $report->patches),
            'vulnerability_status' => (object) $this->vulnerabilityStatus($report),
            'summary' => $report->summary()->counts(),
            'warnings' => [...$report->warnings, ...$report->queue()->warnings],
        ];
        if ($sync !== null) {
            $document['sync'] = ['dry_run' => $dryRun, 'actions' => $sync->toArray()];
        }
        if ($verify !== null) {
            $document['verify'] = $this->verify($verify);
        }
        if ($crossCheck !== null) {
            $document['cross_check'] = $this->crossCheck($crossCheck);
        }

        return new FormattedOutput(
            json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /** @return list<string> */
    private function idsDetectedAs(Report $report, DetectionState $state): array
    {
        $ids = [];
        foreach ($report->patches as $patch) {
            if ($patch->detection->state !== $state) {
                continue;
            }

            $ids[] = $patch->patch->id;
        }

        return $ids;
    }

    /**
     * @return array{id: string, area: string, released: string, state: string, cves: list<string>,
     *     locations: list<string>, requires: list<string>, unmet_requirements: list<string>, warnings: list<string>}
     */
    private function patch(PatchReport $report): array
    {
        return [
            'id' => $report->patch->id,
            'area' => $report->patch->area,
            'released' => $report->patch->released->format('Y-m-d'),
            'state' => $report->state->value,
            'cves' => $report->patch->cves,
            'locations' => array_map(static fn (QueueEntry $entry): string => $entry->path, $report->queueEntries),
            'requires' => $report->patch->requires,
            'unmet_requirements' => $report->unmetRequirements,
            'warnings' => $report->detection->warnings,
        ];
    }

    /** @return array<string, array{status: string, patches: list<string>}> */
    private function vulnerabilityStatus(Report $report): array
    {
        $status = [];
        foreach ($report->cves as $cve => $cveStatus) {
            $patches = [];
            foreach ($report->patches as $patch) {
                if (!in_array($cve, $patch->patch->cves, true)) {
                    continue;
                }

                $patches[] = $patch->patch->id;
            }
            $status[$cve] = ['status' => $cveStatus->value, 'patches' => $patches];
        }

        return $status;
    }

    /** @return array<string, mixed> */
    private function crossCheck(CrossCheckResult $crossCheck): array
    {
        $block = [
            'status' => $crossCheck->status(),
            'compared' => $crossCheck->compared,
            'disagreements' => array_map(static fn (Disagreement $d): array => [
                'id' => $d->id,
                'patchline' => $d->patchline,
                'adobe' => $d->adobe,
            ], $crossCheck->disagreements),
        ];
        if ($crossCheck->warning !== null) {
            $block['warning'] = $crossCheck->warning;
        }

        return $block;
    }

    /** @return array<string, mixed> */
    private function verify(VerifyResult $verify): array
    {
        return [
            'success' => $verify->success,
            'steps' => array_map(static fn (ReplayStep $step): array => [
                'position' => $step->entry->position,
                'path' => $step->entry->path,
                'outcome' => self::outcomeValue($step->outcome),
                'output' => $step->output,
            ], $verify->steps),
            'notes' => $verify->notes,
        ];
    }

    private static function outcomeValue(ReplayOutcome $outcome): string
    {
        return match ($outcome) {
            ReplayOutcome::WouldApply => 'would_apply',
            ReplayOutcome::AlreadyApplied => 'already_applied',
            ReplayOutcome::AlreadyAppliedIgnoringPatchStatus => 'already_applied_ignoring_patch_status',
            ReplayOutcome::SkippedNotInstalled => 'skipped_not_installed',
            ReplayOutcome::Failed => 'failed',
        };
    }

    private static function sourceValue(RegistrySource $source): string
    {
        return match ($source) {
            RegistrySource::Remote => 'remote',
            RegistrySource::Cache => 'cache',
            RegistrySource::StaleCache => 'stale_cache',
            RegistrySource::File => 'file',
        };
    }
}
