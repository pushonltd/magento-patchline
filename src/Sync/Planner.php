<?php

declare(strict_types=1);

namespace Patchline\Sync;

use Patchline\Analysis\PatchReport;
use Patchline\Analysis\PatchState;
use Patchline\Analysis\Report;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;
use Patchline\Registry\RegistryPatch;
use Patchline\Sync\Action\Download;
use Patchline\Sync\Action\Remove;
use Patchline\Sync\Action\Rename;
use Patchline\Sync\Action\Warn;

/** Turns a Report into the Plan that brings m2-hotfixes in line with the registry. Pure: no I/O. */
final class Planner
{
    public function plan(Report $report, SyncOptions $options): Plan
    {
        [$removals, $warnings] = $this->planRemovals($report, $options);
        $removedPaths = array_map(static fn (Remove $remove): string => $remove->path, $removals);

        /** @var array<string, string> $taken path => sha256 of every file the queue will still contain */
        $taken = [];
        foreach ($report->queue()->entries as $entry) {
            if (in_array($entry->path, $removedPaths, true)) {
                continue;
            }

            $taken[$entry->path] = $entry->sha256;
        }

        $renames = [];
        $downloads = [];
        foreach ($report->patches as $patchReport) {
            $target = self::targetPath($patchReport->patch);
            if ($options->adopt) {
                foreach (self::adoptable($patchReport, $target, $removedPaths) as $entry) {
                    if (isset($taken[$target])) {
                        $warnings[] = new Warn(sprintf(
                            'Cannot rename %s: %s already exists. Rename or remove one of them by hand.',
                            $entry->path,
                            $target,
                        ), $patchReport->patch->id);
                        continue;
                    }
                    $renames[] = new Rename($entry->path, $target, $entry->sha256);
                    $taken[$target] = $entry->sha256;
                }
            }
            if (!self::wantsDownload($patchReport, $options)) {
                continue;
            }
            if (isset($taken[$target])) {
                $warnings[] = new Warn(sprintf(
                    'Cannot download %s: %s exists with different content. Move it away and run sync again.',
                    $patchReport->patch->id,
                    $target,
                ), $patchReport->patch->id);
                continue;
            }
            $downloads[] = new Download($patchReport->patch, $target);
            $taken[$target] = $patchReport->patch->sha256;
        }

        $resultingQueue = self::resultingQueue($report, $removals, $renames, $downloads);
        $warnings = [
            ...$warnings,
            ...$this->unsatisfiable($report, $options, $downloads),
            ...$this->orderViolations($report, $options, $resultingQueue),
        ];

        return new Plan([...$removals, ...$renames, ...$downloads, ...$warnings], $resultingQueue);
    }

    /** @return array{list<Remove>, list<Warn>} */
    private function planRemovals(Report $report, SyncOptions $options): array
    {
        $removals = [];
        $warnings = [];
        foreach ($report->patches as $patchReport) {
            if ($patchReport->state !== PatchState::Duplicate) {
                continue;
            }
            $redundant = self::redundantCopies($patchReport);
            if ($redundant === []) {
                $warnings[] = new Warn(sprintf(
                    '%s is queued more than once by magento-cloud-patches itself (%s). '
                    . 'Patchline does not touch those files; fix it in the cloud patches configuration.',
                    $patchReport->patch->id,
                    self::pathList($patchReport->queueEntries),
                ), $patchReport->patch->id);
                continue;
            }
            if (!$options->prune) {
                $warnings[] = new Warn(sprintf(
                    '%s is queued %d times (%s). Run sync with --prune to remove %s.',
                    $patchReport->patch->id,
                    count($patchReport->queueEntries),
                    self::pathList($patchReport->queueEntries),
                    self::pathList($redundant),
                ), $patchReport->patch->id);
                continue;
            }
            foreach ($redundant as $entry) {
                $removals[] = new Remove($entry->path, $entry->sha256);
            }
        }

        return [$removals, $warnings];
    }

    /** @return list<QueueEntry> m2-hotfixes copies that are not needed */
    private static function redundantCopies(PatchReport $patchReport): array
    {
        $hotfixes = array_values(array_filter(
            $patchReport->queueEntries,
            static fn (QueueEntry $entry): bool => $entry->origin === QueueOrigin::Hotfix,
        ));
        if (count($hotfixes) < count($patchReport->queueEntries)) {
            return $hotfixes;
        }
        usort($hotfixes, static fn (QueueEntry $a, QueueEntry $b): int => strcmp($a->path, $b->path));
        $target = self::targetPath($patchReport->patch);
        $keep = $hotfixes[0]->path;
        foreach ($hotfixes as $entry) {
            if ($entry->path !== $target) {
                continue;
            }

            $keep = $target;
        }

        return array_values(array_filter(
            $hotfixes,
            static fn (QueueEntry $entry): bool => $entry->path !== $keep,
        ));
    }

    /**
     * @param list<string> $removedPaths
     * @return list<QueueEntry> hand-copied m2-hotfixes files that should take the target name
     */
    private static function adoptable(PatchReport $patchReport, string $target, array $removedPaths): array
    {
        if ($patchReport->state === PatchState::NotApplicable) {
            return [];
        }

        return array_values(array_filter(
            $patchReport->queueEntries,
            static fn (QueueEntry $entry): bool => $entry->origin === QueueOrigin::Hotfix
                && $entry->path !== $target
                && !in_array($entry->path, $removedPaths, true),
        ));
    }

    private static function wantsDownload(PatchReport $patchReport, SyncOptions $options): bool
    {
        return $patchReport->state === PatchState::Missing
            || ($patchReport->state === PatchState::Entitled && $options->includeEntitled);
    }

    /**
     * @param list<Download> $downloads
     * @return list<Warn>
     */
    private function unsatisfiable(Report $report, SyncOptions $options, array $downloads): array
    {
        $downloaded = array_map(static fn (Download $download): string => $download->patch->id, $downloads);
        $warnings = [];
        foreach ($report->patches as $patchReport) {
            $id = $patchReport->patch->id;
            if ($patchReport->state === PatchState::Entitled && !$options->includeEntitled) {
                $warnings[] = new Warn(sprintf(
                    '%s needs an entitlement this store may not have, so it was not downloaded. '
                    . 'Run sync with --include-entitled if you are entitled to it.',
                    $id,
                ), $id);
            }
            if ($patchReport->state === PatchState::Unknown) {
                $warnings[] = new Warn(sprintf(
                    '%s was not downloaded because patchline cannot tell whether it is already applied%s. '
                    . 'Check it by hand.',
                    $id,
                    $patchReport->detection->warnings === []
                        ? ''
                        : ' (' . implode('; ', $patchReport->detection->warnings) . ')',
                ), $id);
            }
            if (!in_array($id, $downloaded, true)) {
                continue;
            }
            foreach ($patchReport->unmetRequirements as $required) {
                if (in_array($required, $downloaded, true)) {
                    continue;
                }
                $warnings[] = new Warn(sprintf(
                    '%s requires %s, which sync cannot install. %s may not apply until %s is dealt with by hand.',
                    $id,
                    $required,
                    $id,
                    $required,
                ), $id);
            }
        }

        return $warnings;
    }

    /**
     * @param list<Remove> $removals
     * @param list<Rename> $renames
     * @param list<Download> $downloads
     * @return list<QueueEntry>
     */
    private static function resultingQueue(Report $report, array $removals, array $renames, array $downloads): array
    {
        $removed = array_map(static fn (Remove $remove): string => $remove->path, $removals);
        $renamed = [];
        foreach ($renames as $rename) {
            $renamed[$rename->from] = $rename->to;
        }

        $cloud = [];
        $hotfixes = [];
        foreach ($report->queue()->entries as $entry) {
            if ($entry->origin === QueueOrigin::CloudRequired) {
                $cloud[] = $entry;
                continue;
            }
            if (in_array($entry->path, $removed, true)) {
                continue;
            }

            $path = $renamed[$entry->path] ?? $entry->path;
            $hotfixes[] = new QueueEntry(0, $entry->origin, $entry->title, $path, $entry->sha256);
        }
        foreach ($downloads as $download) {
            $hotfixes[] = new QueueEntry(0, QueueOrigin::Hotfix, null, $download->targetPath, $download->patch->sha256);
        }
        usort($hotfixes, static fn (QueueEntry $a, QueueEntry $b): int => strcmp($a->path, $b->path));

        $queue = [];
        foreach ([...$cloud, ...$hotfixes] as $entry) {
            $queue[] = $entry->atPosition(count($queue) + 1);
        }

        return $queue;
    }

    /**
     * @param list<QueueEntry> $queue
     * @return list<Warn>
     */
    private function orderViolations(Report $report, SyncOptions $options, array $queue): array
    {
        $patches = [];
        foreach ($report->patches as $patchReport) {
            if ($patchReport->state === PatchState::NotApplicable) {
                continue;
            }

            $patches[$patchReport->patch->id] = $patchReport->patch;
        }
        $first = [];
        foreach ($queue as $entry) {
            $first[$entry->sha256] ??= $entry;
        }

        $warnings = [];
        foreach ($patches as $id => $patch) {
            $entry = $first[$patch->sha256] ?? null;
            if ($entry === null) {
                continue;
            }
            foreach (self::prerequisites($id, $patches) as $requiredId) {
                $required = $first[$patches[$requiredId]->sha256] ?? null;
                if ($required === null || $required->position < $entry->position) {
                    continue;
                }
                $warnings[] = new Warn(sprintf(
                    '%s would be applied before %s, but %s requires %s. %s',
                    $entry->path,
                    $required->path,
                    $id,
                    $requiredId,
                    $options->adopt
                        ? sprintf('Rename the files by hand so that %s sorts first.', $required->path)
                        : 'Run sync with --adopt to rename hand-copied files into release order.',
                ), $id);
            }
        }

        return $warnings;
    }

    /**
     * @param array<string, RegistryPatch> $patches
     * @return list<string> transitive prerequisites of `$id` that are in `$patches`
     */
    private static function prerequisites(string $id, array $patches): array
    {
        $found = [];
        $pending = [$id];
        while ($pending !== []) {
            $current = array_shift($pending);
            foreach ($patches[$current]->requires as $required) {
                if (!isset($patches[$required]) || in_array($required, $found, true) || $required === $id) {
                    continue;
                }

                $found[] = $required;
                $pending[] = $required;
            }
        }

        return $found;
    }

    /** @param list<QueueEntry> $entries */
    private static function pathList(array $entries): string
    {
        return implode(', ', array_map(static fn (QueueEntry $entry): string => $entry->path, $entries));
    }

    private static function targetPath(RegistryPatch $patch): string
    {
        $name = str_ends_with($patch->fileName, '.diff') ? substr($patch->fileName, 0, -5) : $patch->fileName;

        return sprintf('m2-hotfixes/%s_%s.patch', $patch->released->format('Y-m-d'), $name);
    }
}
