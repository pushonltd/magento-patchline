<?php

declare(strict_types=1);

namespace Patchline\Registry;

final class ParseResult
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly Registry $registry,
        public readonly array $warnings,
    ) {
    }
}
