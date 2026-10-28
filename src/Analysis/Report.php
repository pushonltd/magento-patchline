<?php

declare(strict_types=1);

namespace Patchline\Analysis;

use Patchline\Installation\Edition;
use Patchline\Queue\PatchQueue;
use Patchline\Registry\InstalledComponents;
use Patchline\Registry\RegistrySource;

final class Report
{
    /**
     * @param list<PatchReport> $patches in dependency order
     * @param list<string> $warnings
     * @param array<string, CveStatus> $cves sorted by CVE id
     */
    public function __construct(
        public readonly string $baseVersion,
        public readonly Edition $edition,
        public readonly InstalledComponents $components,
        public readonly RegistrySource $registrySource,
        public readonly array $patches,
        private readonly PatchQueue $queue,
        public readonly array $warnings,
        public readonly array $cves,
    ) {
    }

    public function queue(): PatchQueue
    {
        return $this->queue;
    }

    public function summary(): ReportSummary
    {
        return ReportSummary::of($this->patches);
    }

    /**
     * @param list<PatchState> $failOn
     */
    public function hasFindings(array $failOn): bool
    {
        foreach ($this->patches as $patch) {
            if (in_array($patch->state, $failOn, true)) {
                return true;
            }
        }

        return false;
    }
}
