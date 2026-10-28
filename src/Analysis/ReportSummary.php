<?php

declare(strict_types=1);

namespace Patchline\Analysis;

/** Number of patches per state. */
final class ReportSummary
{
    /**
     * @param array<string, int> $counts state value => count, every state present, in PatchState order
     */
    private function __construct(private readonly array $counts)
    {
    }

    /**
     * @param list<PatchReport> $patches
     */
    public static function of(array $patches): self
    {
        $counts = [];
        foreach (PatchState::cases() as $state) {
            $counts[$state->value] = 0;
        }
        foreach ($patches as $patch) {
            ++$counts[$patch->state->value];
        }

        return new self($counts);
    }

    public function count(PatchState $state): int
    {
        return $this->counts[$state->value];
    }

    /**
     * @return array<string, int> state value => count
     */
    public function counts(): array
    {
        return $this->counts;
    }
}
