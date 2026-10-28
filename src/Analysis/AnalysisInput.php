<?php

declare(strict_types=1);

namespace Patchline\Analysis;

use Patchline\Detection\DetectionResults;
use Patchline\Installation\Installation;
use Patchline\Queue\PatchQueue;
use Patchline\Registry\DependencyGraph;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\RegistrySource;

final class AnalysisInput
{
    /**
     * @param list<\Patchline\Registry\RegistryPatch> $patches applicable patches
     * @param list<string> $warnings collected so far
     */
    public function __construct(
        public readonly Installation $installation,
        public readonly InstalledComponents $components,
        public readonly array $patches,
        public readonly DependencyGraph $graph,
        public readonly DetectionResults $detections,
        public readonly PatchQueue $queue,
        public readonly RegistrySource $registrySource,
        public readonly bool $includeEntitled,
        public readonly array $warnings = [],
    ) {
    }
}
