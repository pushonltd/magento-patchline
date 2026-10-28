<?php

declare(strict_types=1);

namespace Patchline\Detection;

final class DiffSection
{
    public function __construct(
        public readonly string $path,
        public readonly string $text,
    ) {
    }
}
