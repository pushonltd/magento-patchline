<?php

declare(strict_types=1);

namespace Patchline\Cli\Output;

/**
 * What a formatter produced: the main document (written to stdout) plus optional named
 * extra artefacts (for example a Markdown job summary), which the caller routes as it sees fit.
 */
final class FormattedOutput
{
    /**
     * @param array<string, string> $artefacts artefact name => content
     */
    public function __construct(
        public readonly string $document,
        public readonly array $artefacts = [],
    ) {
    }
}
