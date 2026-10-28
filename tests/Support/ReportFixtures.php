<?php

declare(strict_types=1);

namespace Patchline\Tests\Support;

use DateTimeImmutable;
use Patchline\Analysis\CveStatus;
use Patchline\Analysis\PatchReport;
use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\Detection\DetectionResult;
use Patchline\Detection\DetectionState;
use Patchline\Installation\Edition;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\RegistryPatch;
use Patchline\Registry\RegistrySource;

/** Hand-built reports for formatter tests. */
final class ReportFixtures
{
    /** One applied, one queued (with a detection warning), one missing patch; one CVE each way. */
    public static function mixed(): Report
    {
        $a = self::patch('249-2026-09-001-CE', '2026-09-08', ['CVE-2026-0001'], []);
        $b = self::patch('249-2026-10-001-CE', '2026-10-13', ['CVE-2026-0002', 'CVE-2026-0003'], [$a->id]);
        $c = self::patch('249-VULN-39341-CE', '2026-10-20', ['CVE-2026-0004'], [$b->id]);

        $queued = self::entry(1, 'm2-hotfixes/2026-10-13_249-2026-10-001-CE.patch');
        $queue = new PatchQueue([$queued], ['Ignoring m2-hotfixes/notes.txt.']);

        return new Report(
            '2.4.9',
            Edition::OpenSource,
            new InstalledComponents(['CE' => '2.4.9']),
            RegistrySource::Remote,
            [
                new PatchReport($a, PatchState::Applied, DetectionResult::of(DetectionState::Applied), [], []),
                new PatchReport(
                    $b,
                    PatchState::Queued,
                    DetectionResult::of(DetectionState::NotApplied, ['Diff fetched from cache.']),
                    [$queued],
                    [],
                ),
                new PatchReport($c, PatchState::Missing, DetectionResult::of(DetectionState::NotApplied), [], []),
            ],
            $queue,
            ['Registry is older than one hour.'],
            [
                'CVE-2026-0001' => CveStatus::Protected,
                'CVE-2026-0002' => CveStatus::Vulnerable,
                'CVE-2026-0003' => CveStatus::Vulnerable,
                'CVE-2026-0004' => CveStatus::Vulnerable,
            ],
        );
    }

    /**
     * One patch in every state; the queued one has a detection warning; one queue warning and one registry warning;
     * a CVE in each status.
     */
    public static function everyState(RegistrySource $source = RegistrySource::Remote): Report
    {
        $queued = self::entry(1, 'm2-hotfixes/2026-10-13_249-Q-CE.patch');
        $copy = self::entry(2, 'm2-hotfixes/copy.patch');
        $patches = [];
        $cves = [];
        $statuses = CveStatus::cases();
        foreach (PatchState::cases() as $index => $state) {
            $cves['CVE-2026-000' . ($index + 1)] = $statuses[$index % count($statuses)];
            $id = '249-' . strtoupper($state->name) . '-CE';
            $detection = $state === PatchState::Queued
                ? DetectionResult::of(DetectionState::NotApplied, ['Diff fetched from cache.', 'Second warning.'])
                : DetectionResult::of(DetectionState::NotApplied);
            $entries = match ($state) {
                PatchState::Queued => [$queued],
                PatchState::Duplicate => [$queued, $copy],
                default => [],
            };
            $patches[] = new PatchReport(
                self::patch($id, '2026-10-' . sprintf('%02d', $index + 1), ['CVE-2026-000' . ($index + 1)]),
                $state,
                $detection,
                $entries,
                $state === PatchState::Missing ? ['249-PREREQ-CE'] : [],
            );
        }

        return new Report(
            '2.4.9',
            Edition::Commerce,
            new InstalledComponents(['CE' => '2.4.9', 'EE' => '2.4.9']),
            $source,
            $patches,
            new PatchQueue([$queued, $copy], ['Ignoring m2-hotfixes/notes.txt.']),
            ['Registry is older than one hour.'],
            $cves,
        );
    }

    /** No patches at all. */
    public static function empty(): Report
    {
        return new Report(
            '2.4.9',
            Edition::OpenSource,
            new InstalledComponents([]),
            RegistrySource::File,
            [],
            new PatchQueue([]),
            [],
            [],
        );
    }

    /** Everything applied. */
    public static function clean(): Report
    {
        $a = self::patch('249-2026-09-001-CE', '2026-09-08', ['CVE-2026-0001'], []);

        return new Report(
            '2.4.9',
            Edition::Commerce,
            new InstalledComponents(['CE' => '2.4.9', 'EE' => '2.4.9']),
            RegistrySource::Cache,
            [new PatchReport($a, PatchState::Applied, DetectionResult::of(DetectionState::Applied), [], [])],
            new PatchQueue([]),
            [],
            ['CVE-2026-0001' => CveStatus::Protected],
        );
    }

    public static function entry(int $position, string $path): QueueEntry
    {
        return new QueueEntry($position, QueueOrigin::Hotfix, null, $path, str_repeat('b', 64));
    }

    /**
     * @param list<string> $cves
     * @param list<string> $requires
     */
    public static function patch(string $id, string $released, array $cves = [], array $requires = []): RegistryPatch
    {
        $released = new DateTimeImmutable($released);

        return new RegistryPatch($id, ['2.4.9'], 'CE', $requires, $cves, $released, $id . '.diff', str_repeat('a', 64));
    }
}
