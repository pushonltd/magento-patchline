<?php

declare(strict_types=1);

namespace Patchline\Infrastructure;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
