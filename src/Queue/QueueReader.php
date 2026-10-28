<?php

declare(strict_types=1);

namespace Patchline\Queue;

use Patchline\Infrastructure\Filesystem;
use Patchline\Installation\Installation;
use Patchline\Queue\Source\CloudPatchesSource;
use Patchline\Queue\Source\HotfixDirSource;

/** Reads the queue `ece-patches apply` would apply: cloud-patches first, then m2-hotfixes/. */
final class QueueReader
{
    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public function read(Installation $installation): PatchQueue
    {
        return PatchQueue::fromSources(
            (new CloudPatchesSource($this->filesystem))->collect($installation->root, $installation->packages),
            (new HotfixDirSource($this->filesystem))->collect($installation->root),
        );
    }
}
