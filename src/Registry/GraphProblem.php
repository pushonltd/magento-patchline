<?php

declare(strict_types=1);

namespace Patchline\Registry;

final class GraphProblem
{
    public const CYCLE = 'cycle';

    /**
     * @param list<string> $ids
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $ids,
        public readonly string $message,
    ) {
    }
}
