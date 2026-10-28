<?php

declare(strict_types=1);

namespace Patchline\Tests\Double;

use DateTimeImmutable;
use Patchline\Infrastructure\Clock;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
