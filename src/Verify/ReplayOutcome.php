<?php

declare(strict_types=1);

namespace Patchline\Verify;

enum ReplayOutcome
{
    case WouldApply;
    case AlreadyApplied;
    case AlreadyAppliedIgnoringPatchStatus;
    case SkippedNotInstalled;
    case Failed;

    public function label(): string
    {
        return match ($this) {
            self::WouldApply => 'would apply',
            self::AlreadyApplied => 'already applied',
            self::AlreadyAppliedIgnoringPatchStatus => 'already applied (ignoring vendor/bin/patch-status)',
            self::SkippedNotInstalled => 'skipped: target module(s) are not installed',
            self::Failed => 'would fail',
        };
    }
}
