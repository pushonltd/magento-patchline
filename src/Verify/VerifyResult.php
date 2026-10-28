<?php

declare(strict_types=1);

namespace Patchline\Verify;

final class VerifyResult
{
    public readonly bool $success;

    /**
     * @param list<ReplayStep> $steps
     * @param list<string> $notes
     */
    public function __construct(public readonly array $steps, public readonly array $notes = [])
    {
        $this->success = $this->failedStep() === null;
    }

    public function failedStep(): ?ReplayStep
    {
        foreach ($this->steps as $step) {
            if ($step->outcome === ReplayOutcome::Failed) {
                return $step;
            }
        }

        return null;
    }
}
