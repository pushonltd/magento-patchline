<?php

declare(strict_types=1);

namespace Patchline\Registry;

final class LoadedRegistry
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly Registry $registry,
        public readonly RegistrySource $source,
        public readonly array $warnings,
    ) {
    }
}
