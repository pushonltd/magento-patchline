<?php

declare(strict_types=1);

namespace Patchline\Queue;

final class QueueEntry
{
    /**
     * @param int $position 1-based place in the queue (0 until the entry is placed in a PatchQueue)
     * @param string|null $title patches.json title for cloud entries, null for hotfixes
     * @param string $path relative to the store root
     */
    public function __construct(
        public readonly int $position,
        public readonly QueueOrigin $origin,
        public readonly ?string $title,
        public readonly string $path,
        public readonly string $sha256,
    ) {
    }

    public function atPosition(int $position): self
    {
        return new self($position, $this->origin, $this->title, $this->path, $this->sha256);
    }
}
