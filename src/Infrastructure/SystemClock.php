<?php

declare(strict_types=1);

namespace Patchline\Infrastructure;

use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
