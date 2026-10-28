<?php

declare(strict_types=1);

namespace Patchline\Queue\Source;

use Patchline\Infrastructure\Filesystem;
use Patchline\Queue\QueueEntry;
use Patchline\Queue\QueueOrigin;

final class HotfixDirSource
{
    private const DIRECTORY = 'm2-hotfixes';

    public function __construct(private readonly Filesystem $filesystem)
    {
    }

    public function collect(string $root): SourceResult
    {
        $directory = $root . '/' . self::DIRECTORY;
        if (!$this->filesystem->isDirectory($directory)) {
            return new SourceResult([]);
        }

        $entries = [];
        foreach ($this->filesystem->listFiles($directory, '.patch') as $file) {
            $entries[] = new QueueEntry(
                0,
                QueueOrigin::Hotfix,
                null,
                self::DIRECTORY . '/' . basename($file),
                hash('sha256', $this->filesystem->read($file)),
            );
        }

        return new SourceResult($entries);
    }
}
