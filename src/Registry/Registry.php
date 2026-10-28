<?php

declare(strict_types=1);

namespace Patchline\Registry;

use Patchline\Exception\RuntimeFailure;

final class Registry
{
    /**
     * @param array<string, Area> $areas
     * @param array<string, RegistryPatch> $patches keyed by id, in registry order
     */
    public function __construct(
        public readonly string $schemaVersion,
        public readonly string $patchBaseUrl,
        public readonly array $areas,
        public readonly array $patches,
    ) {
    }

    public function has(string $id): bool
    {
        return isset($this->patches[$id]);
    }

    public function patch(string $id): RegistryPatch
    {
        return $this->patches[$id] ?? throw new RuntimeFailure(sprintf('Patch %s is not in the registry.', $id));
    }
}
