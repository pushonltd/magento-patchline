<?php

declare(strict_types=1);

namespace Patchline\Detection;

final class DetectionResult
{
    /**
     * @param list<string> $warnings
     */
    private function __construct(
        public readonly DetectionState $state,
        public readonly array $warnings,
        public readonly string $forwardOutput,
        public readonly string $reverseOutput,
    ) {
    }

    /**
     * @param list<string> $warnings
     */
    public static function of(DetectionState $state, array $warnings = []): self
    {
        return new self($state, $warnings, '', '');
    }

    /**
     * @param list<string> $warnings
     */
    public static function unknown(array $warnings = [], string $forwardOutput = '', string $reverseOutput = ''): self
    {
        return new self(DetectionState::Unknown, $warnings, $forwardOutput, $reverseOutput);
    }

    public function withWarning(string $warning): self
    {
        return new self($this->state, [...$this->warnings, $warning], $this->forwardOutput, $this->reverseOutput);
    }
}
