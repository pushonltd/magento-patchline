<?php

declare(strict_types=1);

namespace Patchline\Registry;

/**
 * The registry areas present in a store, with the version each is installed at.
 */
final class InstalledComponents
{
    /**
     * @param array<string, string> $versions area key => installed version
     */
    public function __construct(public readonly array $versions)
    {
    }

    public function versionOf(string $areaKey): ?string
    {
        return $this->versions[$areaKey] ?? null;
    }
}
