<?php

declare(strict_types=1);

/*
 * A fake GitHub releases host. URLs are /<scenario>/latest/download/<file> or /<scenario>/download/<tag>/<file>.
 * Scenarios: ok, badsum, emptysum, malformed. The "PHAR" is a PHP script that prints the release it came from.
 */
$uri = $_SERVER['REQUEST_URI'] ?? '';
$path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '';

if (preg_match('#^/(\w+)/(?:latest/download|download/(v[\w.]+))/(patchline\.phar(?:\.sha256)?)$#', $path, $m) !== 1) {
    http_response_code(404);

    return;
}

[, $scenario, $tag, $file] = $m;
$phar = "<?php echo 'patchline ' . ('" . ($tag !== '' ? $tag : 'latest') . "') . PHP_EOL;\n";

if ($file === 'patchline.phar') {
    echo $phar;

    return;
}

echo match ($scenario) {
    'badsum' => str_repeat('0', 64) . "  patchline.phar\n",
    'emptysum' => '',
    'malformed' => "not-a-checksum  patchline.phar\n",
    default => hash('sha256', $phar) . "  patchline.phar\n",
};
