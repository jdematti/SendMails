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
        self::$state['active_branch'] = null;
        self::$state['error'] = null;
        self::heartbeat('running', 0);
        return true;
    }

    public static function release(string $status = 'idle', int $processed = 0): void
    {
        if (!is_resource(self::$lock)) return;
        self::$state['active_branch'] = null;
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

    public static function recordError(string $message): void
    {
        self::$state['error'] = mb_substr($message, 0, 1500);
    }

    private static function isBusy(): bool
    {
        $path = STORAGE_PATH . '/worker.lock';
        if (!is_file($path)) return false;
        $file = @fopen($path,'c+');
        if (!$file) return true;
        try {
            if (!flock($file,LOCK_EX | LOCK_NB)) return true;
            flock($file,LOCK_UN); return false;
        } finally { fclose($file); }
    }

    public static function serviceStatus(int $branchId): array
    {
        $control = WorkerControl::read($branchId);
        $worker = self::status();
        $branch = $worker['branches'][(string) $branchId] ?? [];
        $busy = ($worker['status'] ?? '') === 'running' && (int) ($worker['active_branch'] ?? 0) === $branchId && self::isBusy();
        $checked = (int) ($branch['checked_at'] ?? 0);
        $error = (string) ($branch['error'] ?? '');
        if ((int) ($branch['control_revision'] ?? -1) !== (int) $control['revision']) $error = '';
        $healthy = !empty($worker['healthy']) && $checked > 0 && time() - $checked < 180;
        if (!$control['enabled']) {
            $state = $busy ? 'stopping' : 'stopped';
            $label = $busy ? 'Deteniéndose' : 'Detenido';
            $message = $busy ? 'Detención solicitada. El mensaje en curso termina antes de detener esta sucursal.' : 'El proceso automático está detenido para esta sucursal. Los pendientes se conservan.';
        } elseif (!$healthy) {
            $state = 'error'; $label = 'Sin actividad';
            $message = 'No hay actividad reciente del proceso automático. Revisá la tarea SendMails Worker en el servidor.';
            if (($worker['status'] ?? '') === 'error') { $label = 'Con error'; $message = 'El proceso automático informó un error. Revisá el registro del worker en el servidor.'; }
        } elseif ((int) ($branch['control_revision'] ?? -1) !== (int) $control['revision']) {
            $state = 'waiting'; $label = 'Por iniciar';
            $message = 'Inicio solicitado. Los pendientes se retomarán en la próxima ejecución automática, normalmente dentro de un minuto.';
        } elseif ($error !== '') {
            $state = 'error'; $label = 'Con error'; $message = 'La última operación de esta sucursal informó un error.';
        } else {
            $state = 'active'; $label = 'Activo';
            $message = 'Proceso automático activo. Los envíos confirmados continúan aunque cierres el navegador.';
        }
        return ['branch_id'=>$branchId, 'enabled'=>$control['enabled'], 'revision'=>$control['revision'],
            'state'=>$state, 'label'=>$label, 'message'=>$message, 'healthy'=>$state === 'active',
            'last_activity'=>$checked ? date('d/m/Y H:i:s', $checked) : null, 'detail'=>$control['enabled'] ? $error : '',
            'changed_at'=>$control['changed_at'] ? date('d/m/Y H:i:s', (int) $control['changed_at']) : null];
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
                    try {
                        $control = WorkerControl::read((int) $branch);
                        $previous = self::$state['branches'][$branch] ?? [];
                        if ((int) ($previous['control_revision'] ?? -1) !== (int) $control['revision']) { $previous['error'] = ''; $previous['channel_errors'] = []; }
                        self::$state['branches'][$branch] = array_replace($previous, ['checked_at'=>time(), 'control_revision'=>$control['revision']]);
                        if (!$control['enabled']) continue;
                    } catch (Throwable $e) {
                        self::$state['branches'][$branch] = ['checked_at'=>time(), 'control_revision'=>-1, 'error'=>mb_substr($e->getMessage(),0,1500)];
                        continue;
                    }
                    foreach (['email', 'whatsapp'] as $channel) {
                        if ($result['processed'] >= $limit || microtime(true) - self::$started >= $maxSeconds || is_file(STORAGE_PATH . '/maintenance.flag')) break 2;
                        $key = $branch . ':' . $channel;
                        if (isset($exhausted[$key])) continue;
                        if ((int) (self::$state['next_by_channel'][$key] ?? 0) > time()) { $waiting = true; continue; }
                        try {
                            if (!WorkerControl::read((int) $branch)['enabled']) break;
                            self::$state['active_branch'] = (int) $branch;
                            self::heartbeat('running', $result['processed']);
                            $step = UnifiedQueueService::processStep(UnifiedQueueService::TYPE_ALL, $channel, false);
                            if ($step['processed']) {
                                self::$state['branches'][$branch]['channel_errors'][$channel] = mb_substr(implode("\n", $step['errors']),0,1500);
                                self::$state['branches'][$branch]['error'] = implode("\n",array_filter(self::$state['branches'][$branch]['channel_errors']));
                            }
                        } catch (Throwable $e) {
                            self::$state['branches'][$branch]['error'] = mb_substr($e->getMessage(),0,1500);
                            self::$state['branches'][$branch]['channel_errors'][$channel] = self::$state['branches'][$branch]['error'];
                            $result['errors'][] = $e->getMessage();
                            $exhausted[$branch . ':email'] = $exhausted[$branch . ':whatsapp'] = true;
                            break;
                        } finally {
                            self::$state['active_branch'] = null;
                            self::$state['branches'][$branch]['checked_at'] = time();
                            self::heartbeat('running', $result['processed']);
                        }
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
