<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este worker se ejecuta por consola.');
}

set_time_limit(0);

$limit = 100;
foreach ($argv as $arg) {
    if (strncmp($arg, '--limit=', 8) === 0) {
        $limit = normalize_int(substr($arg, 8), 100, 1, 10000);
    }
}

try {
    $result = UnifiedQueueService::processPending(UnifiedQueueService::TYPE_ALL, $limit);
    echo 'Procesados: ' . $result['processed'] . PHP_EOL;
    echo 'Enviados: ' . $result['sent'] . PHP_EOL;
    echo 'Fallidos: ' . $result['failed'] . PHP_EOL;
    echo 'Omitidos: ' . (int) ($result['skipped'] ?? 0) . PHP_EOL;
    if ($result['errors']) {
        echo 'Errores:' . PHP_EOL;
        foreach ($result['errors'] as $error) {
            echo '- ' . $error . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
