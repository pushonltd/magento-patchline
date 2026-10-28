<?php

declare(strict_types=1);

namespace Patchline\CrossCheck;

final class CrossCheckResult
{
    /**
     * @param list<Disagreement> $disagreements
     */
    private function __construct(
        public readonly ?string $warning,
        public readonly int $compared,
        public readonly array $disagreements,
    ) {
    }

    public static function skipped(string $warning): self
    {
        return new self($warning, 0, []);
    }

    /**
     * @param list<Disagreement> $disagreements
     */
    public static function compared(int $compared, array $disagreements): self
    {
        return new self(null, $compared, $disagreements);
    }

    /** @return 'agreed'|'disagreed'|'skipped' */
    public function status(): string
    {
        if (!$this->ran()) {
            return 'skipped';
        }

        return $this->hasDisagreements() ? 'disagreed' : 'agreed';
    }

    /** False when patch-status could not be run, so there was nothing to compare. */
    public function ran(): bool
    {
        return $this->warning === null;
    }

    public function agrees(): bool
    {
        return $this->ran() && $this->disagreements === [];
    }

    /** Whether there is at least one disagreement (a skipped check never counts as one). */
    public function hasDisagreements(): bool
    {
        return $this->disagreements !== [];
    }
}
