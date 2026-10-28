<?php

declare(strict_types=1);

namespace Patchline\Queue;

use Patchline\Detection\DiffParser;
use Patchline\Infrastructure\Filesystem;
use Patchline\Installation\Installation;

/**
 * Turns a queue entry into the diff text ece-patches applies.
 */
final class QueueEntryDiff
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly DiffParser $parser,
    ) {
    }

    /** Same preparation as ece-patches' Applier::prepareContent(), minus sections for replaced packages. */
    public function prepare(Installation $installation, QueueEntry $entry): string
    {
        $content = $this->filesystem->read($installation->root . '/' . $entry->path);
        $content = (string) preg_replace(['/^diff --git/', '/\ndiff --git/'], ['diff -Nuar', "\ndiff -Nuar"], $content);

        return $this->parser->withoutPackages($content, $installation->replacedPackages, [])->text;
    }
}
