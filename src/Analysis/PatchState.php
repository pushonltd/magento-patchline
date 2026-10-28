<?php

declare(strict_types=1);

namespace Patchline\Analysis;

enum PatchState: string
{
    case Applied = 'applied';
    case Queued = 'queued';
    case Duplicate = 'duplicate';
    case Misordered = 'misordered';
    case Missing = 'missing';
    case Entitled = 'entitled';
    case Unknown = 'unknown';
    case NotApplicable = 'not_applicable';
}
