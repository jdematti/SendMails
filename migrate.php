<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/app/bootstrap.php';
$lock = fopen(STORAGE_PATH . '/worker.lock', 'c+');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Hay un proceso de envio activo. Espera a que termine antes de migrar.\n");
    exit(1);
}
try {
    Schema::migrate();
    echo "Migracion completada: " . Schema::VERSION . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Migracion no completada: " . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
