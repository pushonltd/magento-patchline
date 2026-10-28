<?php

declare(strict_types=1);

namespace Patchline\Installation;

final class Installation
{
    /**
     * @param array<string, string> $packages package name => version without leading "v"
     * @param list<string> $replacedPackages
     * @param array<string, scalar|list<string>> $settings `extra.patchline` from composer.json
     */
    public function __construct(
        public readonly string $root,
        public readonly array $packages,
        public readonly array $replacedPackages,
        public readonly Edition $edition,
        public readonly string $baseVersion,
        public readonly ?string $cloudPatchesVersion,
        public readonly bool $cloudPatchesOnDisk,
        public readonly array $settings = [],
    ) {
    }
}
