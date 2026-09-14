<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/app/bootstrap.php';
set_time_limit(0);
$options = getopt('', ['limit::', 'max-seconds::']);
$limit = max(1, min(10000, (int) ($options['limit'] ?? 1000)));
$seconds = max(10, min(3600, (int) ($options['max-seconds'] ?? 55)));
if (!WorkerRuntime::acquire()) { echo "Worker ocupado o mantenimiento activo.\n"; exit(0); }
$status = 'idle'; $processed = 0;
try {
    Schema::ensure();
    WorkerRuntime::flagInterrupted();
    $result = WorkerRuntime::process($limit, $seconds);
    $processed = $result['processed'];
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $e) {
    $status = 'error';
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally { WorkerRuntime::release($status, $processed); }
exit($status === 'error' ? 1 : 0);
