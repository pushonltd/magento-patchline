<?php

declare(strict_types=1);

namespace Patchline\Sync;

enum ActionStatus: string
{
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Warning = 'warning';
    case Planned = 'planned';
}
