<?php

declare(strict_types=1);

namespace Patchline\Detection;

final class FilteredDiff
{
    /**
     * @param list<string> $skippedPackages
     * @param list<string> $presentPackages
     */
    public function __construct(
        public readonly string $text,
        public readonly array $skippedPackages,
        public readonly array $presentPackages,
    ) {
    }

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }
}
