<?php

declare(strict_types=1);

namespace Patchline\Verify;

use Patchline\Queue\QueueEntry;

final class ReplayStep
{
    /**
     * @param string $output patch output, only set for a failed step
     */
    public function __construct(
        public readonly QueueEntry $entry,
        public readonly ReplayOutcome $outcome,
        public readonly string $output = '',
    ) {
    }
}
