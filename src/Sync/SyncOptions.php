<?php

declare(strict_types=1);

namespace Patchline\Sync;

final class SyncOptions
{
    public function __construct(
        public readonly bool $adopt = false,
        public readonly bool $prune = false,
        public readonly bool $includeEntitled = false,
    ) {
    }
}
