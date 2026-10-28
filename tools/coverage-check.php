<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
$minimum = (float) ($argv[2] ?? '0');

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Coverage report not found. Usage: coverage-check.php <clover.xml> <minimum-percent>\n");
    exit(2);
}

$xml = simplexml_load_file($path);
$metrics = $xml === false ? null : $xml->project->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "Cannot read project metrics from {$path}.\n");
    exit(2);
}

$total = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$percent = $total === 0 ? 100.0 : $covered / $total * 100;
$line = sprintf('Line coverage: %.2f%% (minimum %.2f%%)', $percent, $minimum);

if ($percent < $minimum) {
    fwrite(STDERR, $line . ". Add tests for the uncovered lines.\n");
    exit(1);
}

echo $line, "\n";
exit(0);
