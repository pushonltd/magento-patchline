<?php

declare(strict_types=1);

namespace Patchline\Cli;

enum OutputFormat: string
{
    case Text = 'text';
    case Json = 'json';
    case Github = 'github';
    case Junit = 'junit';
}
