<?php

declare(strict_types=1);

namespace Patchline\Analysis;

use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionState;
use Patchline\Registry\RegistryPatch;

/**
 * Combines detection results and the queue into one state per applicable patch. Pure.
 */
final class Analyzer
{
    private const ENTITLEMENT_PACKAGES = [
        'ee' => 'magento/product-enterprise-edition',
        'b2b' => 'magento/extension-b2b',
    ];

    public function analyze(AnalysisInput $input): Report
    {
        $byId = [];
        foreach ($input->patches as $patch) {
            $byId[$patch->id] = $patch;
        }

        $states = [];
        foreach ($byId as $id => $patch) {
            $states[$id] = $this->stateOf($patch, $input);
        }

        $reports = [];
        foreach ($this->orderedIds($byId, $input) as $id) {
            $patch = $byId[$id];
            $reports[] = new PatchReport(
                $patch,
                $states[$id],
                $this->detectionOf($patch, $input),
                $input->queue->entriesWithSha($patch->sha256),
                $this->unmetRequirements($patch, $states, $input),
            );
        }

        $warnings = $input->warnings;
        foreach ($input->graph->problems() as $problem) {
            $warnings[] = $problem->message;
        }

        return new Report(
            $input->installation->baseVersion,
            $input->installation->edition,
            $input->components,
            $input->registrySource,
            $reports,
            $input->queue,
            $warnings,
            $this->cveStatuses($reports),
        );
    }

    private function detectionOf(RegistryPatch $patch, AnalysisInput $input): DetectionResult
    {
        return $input->detections->has($patch->id)
            ? $input->detections->get($patch->id)
            : DetectionResult::unknown();
    }

    private function stateOf(RegistryPatch $patch, AnalysisInput $input): PatchState
    {
        $detection = $this->detectionOf($patch, $input)->state;
        if ($detection === DetectionState::NotApplicable) {
            return PatchState::NotApplicable;
        }

        $entries = $input->queue->entriesWithSha($patch->sha256);
        if (count($entries) >= 2) {
            return PatchState::Duplicate;
        }
        if ($entries !== [] && $this->isQueuedBeforePrerequisite($patch, $entries[0]->position, $input)) {
            return PatchState::Misordered;
        }
        if ($detection === DetectionState::Applied) {
            return PatchState::Applied;
        }
        if ($entries !== []) {
            return PatchState::Queued;
        }
        if (!$input->includeEntitled && $this->lacksEntitlement($patch, $input)) {
            return PatchState::Entitled;
        }

        return $detection === DetectionState::NotApplied ? PatchState::Missing : PatchState::Unknown;
    }

    private function isQueuedBeforePrerequisite(RegistryPatch $patch, int $position, AnalysisInput $input): bool
    {
        foreach ($input->graph->prerequisitesOf($patch->id) as $prerequisiteId) {
            $prerequisite = $this->patchById($prerequisiteId, $input);
            if (
                $prerequisite === null
                || $this->detectionOf($prerequisite, $input)->state === DetectionState::NotApplicable
            ) {
                continue;
            }
            $prerequisitePosition = $input->queue->positionOf($prerequisite->sha256);
            if ($prerequisitePosition !== null && $prerequisitePosition > $position) {
                return true;
            }
        }

        return false;
    }

    private function patchById(string $id, AnalysisInput $input): ?RegistryPatch
    {
        foreach ($input->patches as $patch) {
            if ($patch->id === $id) {
                return $patch;
            }
        }

        return null;
    }

    private function lacksEntitlement(RegistryPatch $patch, AnalysisInput $input): bool
    {
        foreach ($patch->entitlements as $entitlement) {
            $package = self::ENTITLEMENT_PACKAGES[$entitlement] ?? null;
            if ($package === null || !isset($input->installation->packages[$package])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, RegistryPatch> $byId
     * @return list<string>
     */
    private function orderedIds(array $byId, AnalysisInput $input): array
    {
        $ordered = array_values(array_filter(
            $input->graph->order(),
            static fn (string $id): bool => isset($byId[$id]),
        ));

        $rest = array_diff_key($byId, array_flip($ordered));
        uasort(
            $rest,
            static fn (RegistryPatch $a, RegistryPatch $b): int => [$a->released, $a->id] <=> [$b->released, $b->id],
        );

        return [...$ordered, ...array_keys($rest)];
    }

    /**
     * @param array<string, PatchState> $states
     * @return list<string>
     */
    private function unmetRequirements(RegistryPatch $patch, array $states, AnalysisInput $input): array
    {
        $notInPlace = [PatchState::Missing, PatchState::Entitled, PatchState::Unknown];
        $unmet = array_values(array_filter(
            $input->graph->prerequisitesOf($patch->id),
            static fn (string $id): bool => in_array($states[$id] ?? null, $notInPlace, true),
        ));

        return [...$unmet, ...$input->graph->missingRequirements($patch->id)];
    }

    /**
     * @param list<PatchReport> $reports
     * @return array<string, CveStatus>
     */
    private function cveStatuses(array $reports): array
    {
        $perCve = [];
        foreach ($reports as $report) {
            $status = $this->cveStatusOfPatch($report);
            foreach ($report->patch->cves as $cve) {
                $perCve[$cve][] = $status;
            }
        }
        ksort($perCve);

        return array_map(static function (array $statuses): CveStatus {
            foreach ([CveStatus::Vulnerable, CveStatus::Unknown, CveStatus::Protected] as $status) {
                if (in_array($status, $statuses, true)) {
                    return $status;
                }
            }

            return CveStatus::NotApplicable;
        }, $perCve);
    }

    private function cveStatusOfPatch(PatchReport $report): CveStatus
    {
        return match ($report->state) {
            PatchState::Applied, PatchState::Queued => CveStatus::Protected,
            PatchState::NotApplicable => CveStatus::NotApplicable,
            PatchState::Unknown => CveStatus::Unknown,
            PatchState::Missing, PatchState::Entitled => CveStatus::Vulnerable,
            PatchState::Duplicate, PatchState::Misordered => $report->detection->state === DetectionState::Applied
                ? CveStatus::Protected
                : CveStatus::Vulnerable,
        };
    }
}
