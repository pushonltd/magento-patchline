<?php

declare(strict_types=1);

namespace Patchline\Rewind;

final class RewindResult
{
    public readonly bool $success;

    /**
     * @param list<RewindStep> $steps in the order processed (newest queue entry first)
     * @param bool $changed true only when files in the real root were modified
     */
    public function __construct(public readonly array $steps, public readonly bool $changed)
    {
        $this->success = $this->blockedStep() === null;
    }

    public function blockedStep(): ?RewindStep
    {
        foreach ($this->steps as $step) {
            if ($step->outcome === RewindOutcome::Blocked) {
                return $step;
            }
        }

        return null;
    }
}
