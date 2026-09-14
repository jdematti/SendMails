<?php
declare(strict_types=1);

final class WorkerRuntime
{
    private static $lock = null;
    private static float $started = 0;
    private static array $state = [];

    public static function acquire(): bool
    {
        if (is_file(STORAGE_PATH . '/maintenance.flag')) return false;
        self::$lock = @fopen(STORAGE_PATH . '/worker.lock', 'c+');
        if (!self::$lock || !flock(self::$lock, LOCK_EX | LOCK_NB)) {
            if (is_resource(self::$lock)) fclose(self::$lock);
            self::$lock = null;
            return false;
        }
        self::$started = microtime(true);
        self::$state = self::status();
        self::$state['pid'] = getmypid();
        self::heartbeat('running', 0);
        return true;
    }

    public static function release(string $status = 'idle', int $processed = 0): void
    {
        if (!is_resource(self::$lock)) return;
        self::heartbeat($status, $processed);
        flock(self::$lock, LOCK_UN); fclose(self::$lock); self::$lock = null;
    }

    public static function heartbeat(string $status, int $processed): void
    {
        self::$state = array_replace(self::$state, ['status' => $status, 'updated_at' => time(), 'processed' => $processed,
            'started_at' => (int) self::$started, 'elapsed_seconds' => round(microtime(true) - self::$started, 1)]);
        $path = STORAGE_PATH . '/worker-status.json';
        $tmp = $path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode(self::$state, JSON_THROW_ON_ERROR), LOCK_EX);
        if (!@rename($tmp, $path)) @unlink($tmp);
    }

    public static function status(): array
    {
        $path = STORAGE_PATH . '/worker-status.json';
        $row = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $row = is_array($row) ? $row : [];
        $age = time() - (int) ($row['updated_at'] ?? 0);
        $row['healthy'] = $age < 180 && ($row['status'] ?? '') !== 'error';
        return $row;
    }

    public static function process(int $limit, int $maxSeconds): array
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('El procesamiento automatico se ejecuta por consola.');
        $result = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => []];
        $branches = Database::pdo()->query('SELECT id FROM dbo.SendMail_Branches WHERE is_active=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $originalBranch = $_SESSION['branch_id'] ?? null;
        $exhausted = [];
        try {
            while ($result['processed'] < $limit && microtime(true) - self::$started < $maxSeconds) {
                if (is_file(STORAGE_PATH . '/maintenance.flag')) break;
                $waiting = false; $processedInCycle = 0;
                foreach ($branches as $branch) {
                    $_SESSION['branch_id'] = (int) $branch;
                    foreach (['email', 'whatsapp'] as $channel) {
                        if ($result['processed'] >= $limit || microtime(true) - self::$started >= $maxSeconds || is_file(STORAGE_PATH . '/maintenance.flag')) break 2;
                        $key = $branch . ':' . $channel;
                        if (isset($exhausted[$key])) continue;
                        if ((int) (self::$state['next_by_channel'][$key] ?? 0) > time()) { $waiting = true; continue; }
                        $step = UnifiedQueueService::processStep(UnifiedQueueService::TYPE_ALL, $channel, false);
                        if (!$step['processed']) { $exhausted[$key] = true; continue; }
                        foreach (['processed', 'sent', 'failed', 'skipped'] as $field) $result[$field] += (int) ($step[$field] ?? 0);
                        $processedInCycle += (int) $step['processed'];
                        $result['errors'] = array_slice(array_merge($result['errors'], $step['errors']), -20);
                        self::$state['next_by_channel'][$key] = time() + max(0, (int) ($step['interval_seconds'] ?? 0));
                        self::heartbeat('running', $result['processed']);
                    }
                }
                if (!$processedInCycle) {
                    if (!$waiting) break;
                    self::heartbeat('running', $result['processed']);
                    sleep(1);
                }
            }
        } finally {
            if ($originalBranch === null) unset($_SESSION['branch_id']); else $_SESSION['branch_id'] = $originalBranch;
        }
        return $result;
    }

    public static function flagInterrupted(): void
    {
        // The exclusive worker lock proves these claims belong to a previous run.
        foreach (['Queue','InvoiceQueue','WhatsAppQueue'] as $suffix) {
            Database::pdo()->exec("UPDATE dbo.SendMail_$suffix SET last_error='Proceso anterior interrumpido. Verificar resultado antes de reenviar.'
                WHERE status='sending' AND last_error IS NULL");
        }
    }
}
