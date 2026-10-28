<?php

declare(strict_types=1);

use Patchline\Build\PharBuild;

require_once __DIR__ . '/PharBuild.php';

$root = dirname(__DIR__);
$version = null;
$output = $root . '/dist';
$arguments = is_array($_SERVER['argv'] ?? null) ? array_slice($_SERVER['argv'], 1) : [];
foreach ($arguments as $argument) {
    if (!is_string($argument)) {
        continue;
    }
    if (str_starts_with($argument, '--version=')) {
        $version = substr($argument, 10);
        continue;
    }
    if (str_starts_with($argument, '--output=')) {
        $output = rtrim(substr($argument, 9), '/');
        continue;
    }
    fwrite(STDERR, "Usage: php build/build.php [--version=X.Y.Z] [--output=<directory>]\n");
    exit(2);
}

$build = new PharBuild($root);

try {
    $phar = $build->build($version ?? $build->describeVersion(), $output);
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'Build failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, 'Built ' . $phar . "\n");
