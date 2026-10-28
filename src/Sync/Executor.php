<?php

declare(strict_types=1);

namespace Patchline\Sync;

use Patchline\Download\PatchDownloader;
use Patchline\Exception\RuntimeFailure;
use Patchline\Infrastructure\Filesystem;
use Patchline\Sync\Action\Action;
use Patchline\Sync\Action\Download;
use Patchline\Sync\Action\Remove;
use Patchline\Sync\Action\Rename;
use Patchline\Sync\Action\Warn;

/** Carries out a plan on the store: verified, atomic, never overwriting. */
final class Executor
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly PatchDownloader $downloader,
        private readonly string $root,
    ) {
    }

    public function execute(Plan $plan, bool $dryRun = false): ExecutionResult
    {
        $outcomes = [];
        foreach ($plan->actions as $action) {
            $outcomes[] = $this->outcomeFor($action, $dryRun);
        }

        return new ExecutionResult($outcomes);
    }

    private function outcomeFor(Action $action, bool $dryRun): ActionOutcome
    {
        if ($action instanceof Warn) {
            return new ActionOutcome($action, ActionStatus::Warning, $action->message);
        }
        if ($dryRun) {
            return new ActionOutcome($action, ActionStatus::Planned, 'would ' . $action->describe());
        }

        try {
            return $this->run($action);
        } catch (RuntimeFailure $failure) {
            return new ActionOutcome($action, ActionStatus::Failed, $failure->getMessage());
        }
    }

    private function run(Action $action): ActionOutcome
    {
        if ($action instanceof Download) {
            return $this->download($action);
        }
        if ($action instanceof Rename) {
            return $this->rename($action);
        }
        if ($action instanceof Remove) {
            return $this->remove($action);
        }

        return new ActionOutcome($action, ActionStatus::Failed, 'unsupported action: ' . $action->describe());
    }

    private function download(Download $action): ActionOutcome
    {
        $target = $this->absolute($action->targetPath);
        $result = $this->downloader->download($action->patch);
        if ($result->content === null) {
            $reason = $result->failure ?? 'unknown error';

            return $this->failed($action, sprintf('could not download %s: %s', $action->patch->id, $reason));
        }
        if ($this->filesystem->exists($target)) {
            $same = $this->filesystem->isFile($target)
                && hash('sha256', $this->filesystem->read($target)) === $action->patch->sha256;

            return $same
                ? new ActionOutcome($action, ActionStatus::Skipped, $action->targetPath . ' already has this content')
                : $this->failed($action, $action->targetPath . ' exists with different content, left untouched');
        }

        $this->filesystem->writeAtomic($target, $result->content);

        $message = 'downloaded ' . $action->patch->id . ' to ' . $action->targetPath;

        return new ActionOutcome($action, ActionStatus::Done, $message);
    }

    private function rename(Rename $action): ActionOutcome
    {
        $problem = $this->unchangedProblem($action->from, $action->sha256);
        if ($problem !== null) {
            return $this->failed($action, $problem);
        }
        if ($this->filesystem->exists($this->absolute($action->to))) {
            return $this->failed($action, $action->to . ' already exists, left untouched');
        }

        $this->filesystem->rename($this->absolute($action->from), $this->absolute($action->to));

        return new ActionOutcome($action, ActionStatus::Done, $action->describe());
    }

    private function remove(Remove $action): ActionOutcome
    {
        $problem = $this->unchangedProblem($action->path, $action->sha256);
        if ($problem !== null) {
            return $this->failed($action, $problem);
        }

        $this->filesystem->delete($this->absolute($action->path));

        return new ActionOutcome($action, ActionStatus::Done, $action->describe());
    }

    /** Why the file cannot be acted on, or null when it is still the one that was planned. */
    private function unchangedProblem(string $path, string $sha256): ?string
    {
        $absolute = $this->absolute($path);
        if (!$this->filesystem->isFile($absolute)) {
            return $path . ' no longer exists';
        }
        if (hash('sha256', $this->filesystem->read($absolute)) !== $sha256) {
            return $path . ' changed since planning, left untouched';
        }

        return null;
    }

    private function failed(Action $action, string $message): ActionOutcome
    {
        return new ActionOutcome($action, ActionStatus::Failed, $message);
    }

    private function absolute(string $relative): string
    {
        return $this->root . '/' . $relative;
    }
}
