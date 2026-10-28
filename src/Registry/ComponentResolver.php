<?php

declare(strict_types=1);

namespace Patchline\Registry;

use Patchline\Installation\Installation;

/**
 * Maps registry areas to the store's installed versions and selects the patches that apply.
 */
final class ComponentResolver
{
    public function __construct(private readonly Registry $registry)
    {
    }

    public function installedComponents(Installation $installation): InstalledComponents
    {
        $versions = [];
        foreach ($this->registry->areas as $key => $area) {
            $version = $area->detectsByBaseVersion()
                ? $installation->baseVersion
                : ($installation->packages[(string) $area->composerPackage] ?? null);
            if ($version === null) {
                continue;
            }

            $versions[$key] = $version;
        }

        return new InstalledComponents($versions);
    }

    /**
     * Entitlements are not filtered here.
     *
     * @return list<RegistryPatch> in registry order
     */
    public function applicablePatches(Installation $installation): array
    {
        $components = $this->installedComponents($installation);
        $applicable = [];
        foreach ($this->registry->patches as $patch) {
            $version = $components->versionOf($patch->area);
            if ($version === null || !in_array($version, $patch->appliesTo, true)) {
                continue;
            }

            $applicable[] = $patch;
        }

        return $applicable;
    }
}
