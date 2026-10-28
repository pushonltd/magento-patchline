<?php

declare(strict_types=1);

namespace Patchline\Tests\Unit\Cli\Output;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\Report;
use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionState;
use Patchline\Installation\Edition;
use Patchline\Queue\PatchQueue;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\RegistrySource;
use Patchline\Tests\Support\ReportFixtures;

/** Builds reports with one patch per given state, id => state. */
final class StateReports
{
    /**
     * @param array<string, \Patchline\Analysis\PatchState> $states patch id => state
     * @param list<string> $warnings
     */
    public static function of(array $states, array $warnings = [], string $area = 'CE'): Report
    {
        $patches = [];
        foreach ($states as $id => $state) {
            $registryPatch = ReportFixtures::patch($id, '2026-10-13');
            $patches[] = new PatchReport(
                $registryPatch,
                $state,
                DetectionResult::of(DetectionState::NotApplied),
                [],
                [],
            );
        }

        return new Report(
            '2.4.9',
            Edition::OpenSource,
            new InstalledComponents([$area => '2.4.9']),
            RegistrySource::Remote,
            $patches,
            new PatchQueue([]),
            $warnings,
            [],
        );
    }
}
