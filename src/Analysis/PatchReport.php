<?php

declare(strict_types=1);

namespace Patchline\Analysis;

use Patchline\Detection\DetectionResult;
use Patchline\Registry\RegistryPatch;

final class PatchReport
{
    /**
     * @param list<\Patchline\Queue\QueueEntry> $queueEntries entries with this patch's sha256, in queue order
     * @param list<string> $unmetRequirements prerequisites that are neither applied nor queued, or not in scope
     */
    public function __construct(
        public readonly RegistryPatch $patch,
        public readonly PatchState $state,
        public readonly DetectionResult $detection,
        public readonly array $queueEntries,
        public readonly array $unmetRequirements,
    ) {
    }
}
