<?php

declare(strict_types=1);

namespace Patchline\Rewind;

use Patchline\Detection\DiffParser;
use Patchline\Detection\PatchBinary;
use Patchline\Infrastructure\Filesystem;
use Patchline\Installation\Installation;
use Patchline\Queue\PatchQueue;
use Patchline\Queue\QueueEntryDiff;

/**
 * Reverses the queue newest first in the real root, so `ece-patches apply` starts from what `composer install`
 * unpacks. Simulates in a temp copy first and only touches the real root when the queue would then apply cleanly.
 */
final class QueueRewinder
{
    private readonly QueueEntryDiff $entryDiff;

    public function __construct(
        private readonly PatchBinary $patch,
        private readonly DiffParser $parser,
        private readonly Filesystem $filesystem,
    ) {
        $this->entryDiff = new QueueEntryDiff($filesystem, $parser);
    }

    public function rewind(Installation $installation, PatchQueue $queue, bool $dryRun): RewindResult
    {
        $workDir = $this->filesystem->temporaryDirectory();
        try {
            return $this->rewindWith($workDir, $installation, $queue, $dryRun);
        } finally {
            $this->filesystem->removeRecursive($workDir);
        }
    }

    private function rewindWith(
        string $workDir,
        Installation $installation,
        PatchQueue $queue,
        bool $dryRun,
    ): RewindResult {
        $diffs = [];
        foreach ($queue->entries as $i => $entry) {
            $diffs[$i] = $this->entryDiff->prepare($installation, $entry);
            $this->copyTouchedFiles($installation->root, $workDir, $diffs[$i]);
        }

        /** @var array<int, RewindStep> $steps */
        $steps = [];
        foreach (array_reverse(array_keys($queue->entries)) as $i) {
            $steps[$i] = $this->simulateReversal($queue, $i, $diffs[$i], $workDir);
        }

        $reversals = array_keys(array_filter(
            $steps,
            static fn (RewindStep $step): bool => $step->outcome === RewindOutcome::WouldReverse,
        ));
        if ($reversals === []) {
            return $this->result($steps, false);
        }

        $blocked = $this->firstBlocked($queue, $diffs, $workDir);
        if ($blocked !== null) {
            $index = $blocked['index'];
            $steps[$index] = $steps[$index]->withOutcome(RewindOutcome::Blocked, $blocked['output']);

            return $this->result($steps, false);
        }
        if ($dryRun) {
            return $this->result($steps, false);
        }

        return $this->commit($installation->root, $reversals, $diffs, $steps);
    }

    private function simulateReversal(PatchQueue $queue, int $i, string $diff, string $workDir): RewindStep
    {
        $entry = $queue->entries[$i];
        if (trim($diff) === '') {
            return new RewindStep($entry, RewindOutcome::SkippedNotInstalled);
        }
        if (!$this->touchesExistingFile($diff, $workDir) || !$this->patch->dryRun($diff, $workDir, true)->succeeded) {
            return new RewindStep($entry, RewindOutcome::NotApplied);
        }

        $this->patch->apply($diff, $workDir, true);
        $this->deleteEmptyCreatedFiles($diff, $workDir);

        return new RewindStep($entry, RewindOutcome::WouldReverse);
    }

    /**
     * Replays the queue oldest first on the simulated pristine copy with ece-patches semantics.
     *
     * @param array<int, string> $diffs
     * @return array{index: int, output: string}|null
     */
    private function firstBlocked(PatchQueue $queue, array $diffs, string $workDir): ?array
    {
        foreach (array_keys($queue->entries) as $i) {
            $diff = $diffs[$i];
            if (trim($diff) === '') {
                continue;
            }
            $forward = $this->patch->dryRun($diff, $workDir, false);
            if ($forward->succeeded) {
                $this->patch->apply($diff, $workDir, false);
                continue;
            }
            if (!$this->patch->dryRun($diff, $workDir, true)->succeeded) {
                return ['index' => $i, 'output' => $forward->output];
            }
        }

        return null;
    }

    /**
     * @param list<int> $reversals queue indexes, newest first
     * @param array<int, string> $diffs
     * @param array<int, RewindStep> $steps
     */
    private function commit(string $root, array $reversals, array $diffs, array $steps): RewindResult
    {
        $changed = false;
        foreach ($reversals as $i) {
            $diff = $diffs[$i];
            $before = $this->strayFiles($diff, $root);
            $dryRun = $this->patch->dryRun($diff, $root, true);
            $run = $dryRun->succeeded ? $this->patch->apply($diff, $root, true) : $dryRun;
            $this->deleteNewStrayFiles($diff, $root, $before);
            if (!$run->succeeded) {
                $steps[$i] = $steps[$i]->withOutcome(RewindOutcome::Blocked, $run->output);

                return $this->result($steps, $changed);
            }

            $changed = true;
            $this->deleteEmptyCreatedFiles($diff, $root);
            $steps[$i] = $steps[$i]->withOutcome(RewindOutcome::Reversed);
        }

        return $this->result($steps, $changed);
    }

    /**
     * @param array<int, RewindStep> $steps keyed by queue index, newest first
     */
    private function result(array $steps, bool $changed): RewindResult
    {
        return new RewindResult(array_values($steps), $changed);
    }

    /** A patch cannot be reversed if none of its files exist (patch would "reverse" a creation out of thin air). */
    private function touchesExistingFile(string $diff, string $workDir): bool
    {
        foreach ($this->parser->files($diff) as $file) {
            if ($this->filesystem->isFile($workDir . '/' . $file)) {
                return true;
            }
        }

        return false;
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

    /** GNU patch removes a reversed creation, BSD patch without -E leaves an empty file that blocks re-creating it. */
    private function deleteEmptyCreatedFiles(string $diff, string $dir): void
    {
        foreach ($this->parser->sections($diff) as $section) {
            $path = $dir . '/' . $section->path;
            $isCreation = $section->path !== '' && preg_match('/^--- \/dev\/null(?:\s|$)/m', $section->text) === 1;
            if (!$isCreation || !$this->filesystem->isFile($path) || $this->filesystem->read($path) !== '') {
                continue;
            }

            $this->filesystem->delete($path);
        }
    }

    /**
     * @return list<string> existing `.orig` / `.rej` files next to the diff's files
     */
    private function strayFiles(string $diff, string $root): array
    {
        $candidates = [];
        foreach ($this->parser->files($diff) as $file) {
            foreach (['.orig', '.rej'] as $suffix) {
                $candidates[] = $file . $suffix;
            }
        }

        return array_values(array_filter(
            $candidates,
            fn (string $candidate): bool => $this->filesystem->exists($root . '/' . $candidate),
        ));
    }

    /**
     * @param list<string> $before
     */
    private function deleteNewStrayFiles(string $diff, string $root, array $before): void
    {
        foreach (array_diff($this->strayFiles($diff, $root), $before) as $stray) {
            $this->filesystem->delete($root . '/' . $stray);
        }
    }
}
