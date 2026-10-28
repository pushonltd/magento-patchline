<?php

declare(strict_types=1);

namespace Patchline\Installation;

enum Edition: string
{
    case OpenSource = 'open-source';
    case Commerce = 'commerce';
}
