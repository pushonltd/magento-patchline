<?php

declare(strict_types=1);

namespace Patchline\Queue;

use Patchline\Queue\Source\SourceResult;

/** The patch files `ece-patches apply` applies, in order. */
final class PatchQueue
{
    /**
     * @param list<QueueEntry> $entries
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $warnings = [],
    ) {
    }

    public static function fromSources(SourceResult $cloud, SourceResult $hotfixes): self
    {
        $entries = [];
        foreach ([...$cloud->entries, ...$hotfixes->entries] as $entry) {
            $entries[] = $entry->atPosition(count($entries) + 1);
        }

        return new self($entries, [...$cloud->warnings, ...$hotfixes->warnings]);
    }

    /** @return list<QueueEntry> */
    public function entriesWithSha(string $sha256): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (QueueEntry $entry): bool => $entry->sha256 === $sha256,
        ));
    }

    public function positionOf(string $sha256): ?int
    {
        return $this->entriesWithSha($sha256)[0]->position ?? null;
    }
}
