<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Api.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$api = new Api(new State());
[$status, $body, $delayMs] = $api->handle($method, $path);

if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($body, JSON_UNESCAPED_SLASHES);
