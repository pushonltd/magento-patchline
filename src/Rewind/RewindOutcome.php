<?php

declare(strict_types=1);

namespace Patchline\Rewind;

enum RewindOutcome
{
    case Reversed;
    case WouldReverse;
    case NotApplied;
    case SkippedNotInstalled;
    case Blocked;

    public function label(): string
    {
        return match ($this) {
            self::Reversed => 'reversed',
            self::WouldReverse => 'would reverse',
            self::NotApplied => 'not applied',
            self::SkippedNotInstalled => 'skipped: target module(s) are not installed',
            self::Blocked => 'blocked',
        };
    }
}
