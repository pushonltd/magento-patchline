<?php

declare(strict_types=1);

namespace Patchline\Registry;

/**
 * A component a patch can target. Detected either through the Magento base version
 * or through an installed composer package.
 */
final class Area
{
    public function __construct(
        public readonly string $key,
        public readonly string $description,
        public readonly ?string $composerPackage,
    ) {
    }

    public function detectsByBaseVersion(): bool
    {
        return $this->composerPackage === null;
    }
}
