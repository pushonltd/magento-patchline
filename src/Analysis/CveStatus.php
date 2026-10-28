<?php

declare(strict_types=1);

namespace Patchline\Analysis;

enum CveStatus: string
{
    case Protected = 'PROTECTED';
    case Vulnerable = 'VULNERABLE';
    case Unknown = 'UNKNOWN';
    case NotApplicable = 'NOT_APPLICABLE';
}
