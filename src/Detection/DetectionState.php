<?php

declare(strict_types=1);

namespace Patchline\Detection;

enum DetectionState: string
{
    case Applied = 'applied';
    case NotApplied = 'not_applied';
    case Unknown = 'unknown';
    case NotApplicable = 'not_applicable';
}
