<?php

declare(strict_types=1);

namespace Patchline\Rewind;

use Patchline\Queue\QueueEntry;

final class RewindStep
{
    /**
     * @param string $output patch output, only set for a blocked step
     */
    public function __construct(
        public readonly QueueEntry $entry,
        public readonly RewindOutcome $outcome,
        public readonly string $output = '',
    ) {
    }

    public function withOutcome(RewindOutcome $outcome, string $output = ''): self
    {
        return new self($this->entry, $outcome, $output);
    }
}
