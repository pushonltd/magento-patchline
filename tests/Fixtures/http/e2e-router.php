<?php

declare(strict_types=1);

/**
 * Stands in for repo.magento.com in the end-to-end tests.
 *
 *   /patch/<file>.diff       public diff
 *   /patch/auth/<file>.diff  the same diff, but only with Basic auth e2e-user:e2e-secret (401 otherwise)
 */
$server = array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $_SERVER);
$path = (string) parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH);

if (preg_match('#^/patch/(auth/)?([A-Za-z0-9._-]+\.diff)$#', $path, $match) !== 1) {
    http_response_code(404);
    echo 'missing';

    return;
}

$file = __DIR__ . '/../diffs/' . $match[2];
if ($match[1] === 'auth/') {
    $expected = 'Basic ' . base64_encode('e2e-user:e2e-secret');
    if (($server['HTTP_AUTHORIZATION'] ?? '') !== $expected) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="e2e"');
        echo 'denied';

        return;
    }
}

if (!is_file($file)) {
    http_response_code(404);
    echo 'missing';

    return;
}

header('Content-Type: text/plain');
readfile($file);
