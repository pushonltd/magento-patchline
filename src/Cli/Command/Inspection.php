<?php

declare(strict_types=1);

namespace Patchline\Cli\Command;

use Patchline\Analysis\Report;
use Patchline\Installation\Installation;

final class Inspection
{
    public function __construct(
        public readonly Installation $installation,
        public readonly Report $report,
    ) {
    }
}
