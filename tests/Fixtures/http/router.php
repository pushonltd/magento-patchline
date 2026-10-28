<?php

declare(strict_types=1);

$server = array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $_SERVER);
$path = parse_url($server['REQUEST_URI'] ?? '', PHP_URL_PATH);
$port = $server['SERVER_PORT'] ?? '';

switch ($path) {
    case '/ok':
        header('Content-Type: text/plain');
        echo 'hello';
        break;
    case '/unauthorized':
        http_response_code(401);
        echo 'denied';
        break;
    case '/echo-auth':
        echo ($server['HTTP_AUTHORIZATION'] ?? '') !== '' ? $server['HTTP_AUTHORIZATION'] : 'none';
        break;
    case '/echo-ua':
        echo $server['HTTP_USER_AGENT'] ?? 'none';
        break;
    case '/redirect-same-host':
        header('Location: /echo-auth', true, 302);
        break;
    case '/redirect-other-host':
        header('Location: http://localhost:' . $port . '/echo-auth', true, 302);
        break;
    case '/redirect-loop':
        header('Location: /redirect-loop', true, 302);
        break;
    default:
        http_response_code(404);
        echo 'missing';
}
