<?php

declare(strict_types=1);

namespace Patchline\Registry;

final class RegistryPatch
{
    /**
     * @param list<string> $appliesTo
     * @param list<string> $requires
     * @param list<string> $cves
     * @param list<string> $entitlements
     */
    public function __construct(
        public readonly string $id,
        public readonly array $appliesTo,
        public readonly string $area,
        public readonly array $requires,
        public readonly array $cves,
        public readonly \DateTimeImmutable $released,
        public readonly string $fileName,
        public readonly string $sha256,
        public readonly array $entitlements = [],
    ) {
    }
}
