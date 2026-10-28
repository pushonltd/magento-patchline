<?php

declare(strict_types=1);

namespace Patchline\Verify;

use Patchline\Detection\DiffParser;
use Patchline\Detection\PatchBinary;
use Patchline\Infrastructure\Filesystem;
use Patchline\Installation\Installation;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntry;

/**
 * Predicts `ece-patches apply`: replays the queue in a temp copy of the files it touches.
 */
final class QueueReplayer
{
    private const PATCH_STATUS_OVERLAP_NOTE = 'Patch %s only counts as already applied when vendor/bin/patch-status'
        . ' is ignored (every monthly patch rewrites that file). Stock magento-cloud-patches does not ignore it,'
        . ' so ece-patches apply will fail here unless it is patched to do so.';

    private const IN_PLACE_NOTE = 'Re-running ece-patches apply on this already patched vendor/ would stop at %s'
        . ' (a newer patch changed the same lines). A fresh composer install applies cleanly.';

    public function __construct(
        private readonly PatchBinary $patch,
        private readonly DiffParser $parser,
        private readonly Filesystem $filesystem,
    ) {
    }

    public function replay(Installation $installation, PatchQueue $queue): VerifyResult
    {
        $workDir = $this->filesystem->temporaryDirectory();
        try {
            return $this->replayIn($workDir, $installation, $queue);
        } finally {
            $this->filesystem->removeRecursive($workDir);
        }
    }

    private function replayIn(string $workDir, Installation $installation, PatchQueue $queue): VerifyResult
    {
        $freshDir = $workDir . '/fresh';
        $inPlaceDir = $workDir . '/in-place';
        $this->filesystem->makeDirectory($freshDir);
        $this->filesystem->makeDirectory($inPlaceDir);
        $diffs = [];
        foreach ($queue->entries as $entry) {
            $diff = $this->prepare($installation, $entry);
            $this->copyTouchedFiles($installation->root, $freshDir, $diff);
            $this->copyTouchedFiles($installation->root, $inPlaceDir, $diff);
            $diffs[] = $diff;
        }

        $this->restorePristine($queue, $diffs, $freshDir);
        $notes = [];
        $fresh = $this->replayQueue($queue, $diffs, $freshDir);
        if ($fresh->failedStep() !== null) {
            return new VerifyResult($fresh->steps, array_merge($notes, $fresh->notes));
        }

        $inPlaceFailure = $this->replayQueue($queue, $diffs, $inPlaceDir)->failedStep();
        if ($inPlaceFailure !== null) {
            $notes[] = sprintf(self::IN_PLACE_NOTE, $inPlaceFailure->entry->path);
        }

        return new VerifyResult($fresh->steps, array_merge($notes, $fresh->notes));
    }

    /**
     * Reverses applied queue entries newest first, so the work dir holds what a clean `composer install` unpacks.
     * Entries that do not reverse are left alone; the replay reports them if they then fail to apply.
     *
     * @param list<string> $diffs
     */
    private function restorePristine(PatchQueue $queue, array $diffs, string $workDir): void
    {
        foreach (array_reverse(array_keys($queue->entries)) as $i) {
            $diff = $diffs[$i];
            if (!$this->touchesExistingFile($diff, $workDir)) {
                continue;
            }
            if (!$this->patch->dryRun($diff, $workDir, true)->succeeded) {
                continue;
            }

            $this->patch->apply($diff, $workDir, true);
        }
    }

    /** A patch cannot be applied if none of its files exist (patch would "reverse" a file creation out of thin air). */
    private function touchesExistingFile(string $diff, string $workDir): bool
    {
        foreach ($this->parser->files($diff) as $file) {
            if ($this->filesystem->isFile($workDir . '/' . $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $diffs
     */
    private function replayQueue(PatchQueue $queue, array $diffs, string $workDir): VerifyResult
    {
        $steps = [];
        $notes = [];
        foreach ($queue->entries as $i => $entry) {
            $step = $this->replayEntry($entry, $diffs[$i], $workDir);
            $steps[] = $step;
            if ($step->outcome === ReplayOutcome::AlreadyAppliedIgnoringPatchStatus) {
                $notes[] = sprintf(self::PATCH_STATUS_OVERLAP_NOTE, $entry->path);
            }
            if ($step->outcome === ReplayOutcome::Failed) {
                break;
            }
        }

        return new VerifyResult($steps, $notes);
    }

    /** Same preparation as ece-patches' Applier::prepareContent(). */
    private function prepare(Installation $installation, QueueEntry $entry): string
    {
        $content = $this->filesystem->read($installation->root . '/' . $entry->path);
        $content = (string) preg_replace(['/^diff --git/', '/\ndiff --git/'], ['diff -Nuar', "\ndiff -Nuar"], $content);

        return $this->parser->withoutPackages($content, $installation->replacedPackages, [])->text;
    }

    private function copyTouchedFiles(string $root, string $workDir, string $diff): void
    {
        foreach ($this->parser->files($diff) as $file) {
            if (!$this->filesystem->isFile($root . '/' . $file)) {
                continue;
            }

            $this->filesystem->copy($root . '/' . $file, $workDir . '/' . $file);
        }
    }

    private function replayEntry(QueueEntry $entry, string $diff, string $workDir): ReplayStep
    {
        if (trim($diff) === '') {
            return new ReplayStep($entry, ReplayOutcome::SkippedNotInstalled);
        }

        $forward = $this->patch->dryRun($diff, $workDir, false);
        if ($forward->succeeded) {
            $this->patch->apply($diff, $workDir, false);

            return new ReplayStep($entry, ReplayOutcome::WouldApply);
        }
        if ($this->patch->dryRun($diff, $workDir, true)->succeeded) {
            return new ReplayStep($entry, ReplayOutcome::AlreadyApplied);
        }
        $withoutTool = $this->parser->withoutCumulativeTool($diff);
        if ($this->patch->dryRun($withoutTool, $workDir, true)->succeeded) {
            return new ReplayStep($entry, ReplayOutcome::AlreadyAppliedIgnoringPatchStatus);
        }

        return new ReplayStep($entry, ReplayOutcome::Failed, $forward->output);
    }
}
