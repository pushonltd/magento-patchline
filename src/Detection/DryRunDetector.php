<?php

declare(strict_types=1);

namespace Patchline\Detection;

use Patchline\Download\PatchDownloader;
use Patchline\Infrastructure\Filesystem;
use Patchline\Installation\Installation;
use Patchline\Registry\DependencyGraph;
use Patchline\Registry\RegistryPatch;

/**
 * Port of the on-disk detection of Adobe's patch-status: forward and reverse `patch --dry-run`, with
 * later overlapping patches reversed (and earlier ones applied) in a temporary copy of the touched files.
 */
final class DryRunDetector
{
    /** @var array<string, RegistryPatch> */
    private array $patches = [];

    public function __construct(
        private readonly Installation $installation,
        private readonly PatchDownloader $downloader,
        private readonly DiffParser $parser,
        private readonly PatchBinary $binary,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<RegistryPatch> $applicable
     */
    public function detect(array $applicable, DependencyGraph $graph): DetectionResults
    {
        $this->patches = [];
        foreach ($applicable as $patch) {
            $this->patches[$patch->id] = $patch;
        }

        $results = [];
        foreach ($applicable as $patch) {
            $results[$patch->id] = $this->detectOne($patch, $graph);
        }

        return new DetectionResults($results);
    }

    private function detectOne(RegistryPatch $patch, DependencyGraph $graph): DetectionResult
    {
        $download = $this->downloader->download($patch);
        if ($download->content === null) {
            return DetectionResult::unknown([sprintf(
                'The diff of %s could not be downloaded (%s); its state is unknown.',
                $patch->id,
                $download->failure ?? 'unknown reason',
            )]);
        }
        $filtered = $this->withoutReplacedPackages($download->content);
        if ($filtered->skippedPackages !== [] && $filtered->isEmpty()) {
            return DetectionResult::of(DetectionState::NotApplicable, [sprintf(
                'Patch %s only changes replaced package(s) %s; marked as not applicable.',
                $patch->id,
                implode(', ', $filtered->skippedPackages),
            )]);
        }

        $warnings = $download->warnings;
        if ($filtered->presentPackages !== []) {
            $warnings[] = sprintf(
                'Patch %s: files of replaced package(s) %s are present although the package is replaced; '
                . 'checking them anyway.',
                $patch->id,
                implode(', ', $filtered->presentPackages),
            );
        }

        return $this->detectWithDependencies($patch, $filtered->text, $graph, $warnings);
    }

    /**
     * @param list<string> $warnings
     */
    private function detectWithDependencies(
        RegistryPatch $patch,
        string $diff,
        DependencyGraph $graph,
        array $warnings,
    ): DetectionResult {
        $files = $this->parser->files($diff);
        $descendantIds = array_reverse($graph->descendantsOf($patch->id));
        $descendantDiffs = $this->dependencyDiffs($descendantIds);
        $unavailable = array_diff($descendantIds, array_keys($descendantDiffs));
        if ($unavailable !== []) {
            $warnings[] = sprintf(
                'Descendant diffs unavailable for %s; the dry-run for %s may be inaccurate.',
                implode(', ', $unavailable),
                $patch->id,
            );
        }
        $descendants = $this->overlapping($descendantDiffs, $files);
        $prerequisites = $this->overlapping(
            $this->dependencyDiffs($this->oldestFirst($graph->prerequisitesOf($patch->id), $graph)),
            $files,
        );
        $touching = array_merge([$diff], array_values($descendants), array_values($prerequisites));

        $tempDir = null;
        try {
            $workDir = $this->installation->root;
            $sanitized = true;
            if ($descendants !== []) {
                $tempDir = $this->copyTouchedFiles($touching);
                $failure = $this->reverseAll($tempDir, $patch->id, $descendants);
                $workDir = $tempDir;
                if ($failure !== null) {
                    $warnings[] = $failure;
                    $this->filesystem->removeRecursive($tempDir);
                    $tempDir = null;
                    $workDir = $this->installation->root;
                    $sanitized = false;
                }
            }

            $result = $this->classify($diff, $workDir, $warnings);
            if ($result->state !== DetectionState::Unknown || $prerequisites === [] || !$sanitized) {
                return $result;
            }

            $tempDir ??= $this->copyTouchedFiles($touching);
            $failure = $this->applyAll($tempDir, $patch->id, $prerequisites);
            if ($failure !== null) {
                return $result->withWarning($failure);
            }

            return $this->classify($diff, $tempDir, $warnings);
        } finally {
            if ($tempDir !== null) {
                $this->filesystem->removeRecursive($tempDir);
            }
        }
    }

    private function withoutReplacedPackages(string $diff): FilteredDiff
    {
        $replaced = $this->installation->replacedPackages;
        if ($replaced === []) {
            return new FilteredDiff($diff, [], []);
        }

        $presentPaths = array_values(array_filter(
            $this->parser->files($diff),
            fn (string $path): bool => $this->filesystem->exists($this->installation->root . '/' . $path),
        ));

        return $this->parser->withoutPackages($diff, $replaced, $presentPaths);
    }

    /**
     * @param list<string> $ids
     * @return list<string> topological order, prerequisites first
     */
    private function oldestFirst(array $ids, DependencyGraph $graph): array
    {
        return array_values(array_intersect($graph->order(), $ids));
    }

    /**
     * @param list<string> $ids
     * @return array<string, string> filtered diff text by id (in the order of $ids) of the ids that could be downloaded
     */
    private function dependencyDiffs(array $ids): array
    {
        $diffs = [];
        foreach ($ids as $id) {
            $content = $this->downloader->download($this->patches[$id])->content;
            if ($content === null) {
                continue;
            }

            $diffs[$id] = $this->withoutReplacedPackages($content)->text;
        }

        return $diffs;
    }

    /**
     * @param array<string, string> $diffs
     * @param list<string> $files
     * @return array<string, string> the diffs that touch one of $files, or a file of another diff returned, in order
     */
    private function overlapping(array $diffs, array $files): array
    {
        $selected = [];
        do {
            $added = false;
            foreach ($diffs as $id => $diff) {
                $touched = $this->parser->files($diff);
                if (isset($selected[$id]) || array_intersect($touched, $files) === []) {
                    continue;
                }

                $selected[$id] = true;
                $files = array_merge($files, $touched);
                $added = true;
            }
        } while ($added);

        return array_intersect_key($diffs, $selected);
    }

    /**
     * @param list<string> $diffs
     */
    private function copyTouchedFiles(array $diffs): string
    {
        $paths = [];
        foreach ($diffs as $diff) {
            array_push($paths, ...$this->parser->files($diff));
        }

        $tempDir = $this->filesystem->temporaryDirectory();
        foreach (array_unique($paths) as $path) {
            $source = $this->installation->root . '/' . $path;
            if (!$this->filesystem->isFile($source)) {
                continue;
            }

            $this->filesystem->copy($source, $tempDir . '/' . $path);
        }

        return $tempDir;
    }

    /**
     * Reverses each diff (newest first) that currently reverses cleanly.
     *
     * @param array<string, string> $descendants
     */
    private function reverseAll(string $workDir, string $patchId, array $descendants): ?string
    {
        foreach ($descendants as $diff) {
            if (!$this->binary->dryRun($diff, $workDir, true)->succeeded) {
                continue;
            }
            if (!$this->binary->apply($diff, $workDir, true)->succeeded) {
                return sprintf(
                    'Could not reverse a later patch while checking %s; the result may be inaccurate.',
                    $patchId,
                );
            }
        }

        return null;
    }

    /**
     * Rewinds the prerequisites (newest first), then applies them all (oldest first). An applied prerequisite with a
     * newer one on top does not reverse on its own (every monthly patch rewrites vendor/bin/patch-status), so it can
     * only be recognised once the newer ones are out of the way.
     *
     * @param array<string, string> $prerequisites oldest first
     */
    private function applyAll(string $workDir, string $patchId, array $prerequisites): ?string
    {
        $failure = $this->reverseAll($workDir, $patchId, array_reverse($prerequisites));
        if ($failure !== null) {
            return $failure;
        }

        foreach ($prerequisites as $diff) {
            if (!$this->binary->apply($diff, $workDir, false)->succeeded) {
                return sprintf(
                    'Could not apply an earlier patch while checking %s; the result may be inaccurate.',
                    $patchId,
                );
            }
        }

        return null;
    }

    /**
     * @param list<string> $warnings
     */
    private function classify(string $diff, string $workDir, array $warnings): DetectionResult
    {
        $forward = $this->binary->dryRun($diff, $workDir, false);
        $reverse = $this->binary->dryRun($diff, $workDir, true);

        return match (true) {
            !$forward->succeeded && $reverse->succeeded => DetectionResult::of(DetectionState::Applied, $warnings),
            $forward->succeeded && !$reverse->succeeded => DetectionResult::of(DetectionState::NotApplied, $warnings),
            default => DetectionResult::unknown($warnings, $forward->output, $reverse->output),
        };
    }
}
