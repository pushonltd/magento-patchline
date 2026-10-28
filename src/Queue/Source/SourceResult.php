<?php

declare(strict_types=1);

namespace Patchline\Queue\Source;

final class SourceResult
{
    /**
     * @param list<\Patchline\Queue\QueueEntry> $entries
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $warnings = [],
    ) {
    }
}
