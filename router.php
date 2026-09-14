<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (strncmp($path, '/storage/', 9) === 0) {
    http_response_code(403);
    exit('Acceso denegado.');
}

$file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);
if (PHP_SAPI === 'cli-server' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
