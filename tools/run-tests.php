<?php

declare(strict_types=1);

$hasDriver = extension_loaded('pcov') || extension_loaded('xdebug');
$script = $hasDriver ? 'test:coverage' : 'test';

if (!$hasDriver) {
    echo "No coverage driver (pcov/xdebug) loaded: running tests without the coverage gate.\n";
}

passthru('composer ' . escapeshellarg($script), $exitCode);
exit($exitCode);
