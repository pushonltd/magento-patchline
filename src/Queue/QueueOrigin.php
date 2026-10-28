<?php

declare(strict_types=1);

namespace Patchline\Queue;

enum QueueOrigin
{
    case CloudRequired;
    case Hotfix;
}
