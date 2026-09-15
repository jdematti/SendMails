<?php
declare(strict_types=1);

final class WorkerControl
{
    private static function path(int $branchId): string
    {
        if ($branchId <= 0) throw new InvalidArgumentException('Seleccioná una sucursal.');
        return STORAGE_PATH . '/worker-control-' . $branchId . '.json';
    }

    private static function decode(string $json): array
    {
        if ($json === '') return ['enabled'=>true, 'revision'=>0, 'changed_at'=>null, 'changed_by'=>null];
        $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || !is_bool($state['enabled'] ?? null) || !isset($state['revision'])) {
            throw new RuntimeException('No se pudo leer el control del proceso automático.');
        }
        return $state;
    }

    public static function read(int $branchId): array
    {
        $path = self::path($branchId);
        clearstatcache(true, $path);
        if (!is_file($path)) return self::decode('');
        $file = @fopen($path, 'rb');
        if (!$file) throw new RuntimeException('No se pudo leer el control del proceso automático.');
        try {
            if (!flock($file, LOCK_SH)) throw new RuntimeException('El control del proceso está ocupado.');
            return self::decode((string) stream_get_contents($file));
        } finally { fclose($file); }
    }

    public static function set(int $branchId, bool $enabled, int $userId, int $expectedRevision): array
    {
        $file = @fopen(self::path($branchId), 'c+');
        if (!$file) throw new RuntimeException('No se pudo guardar el control del proceso automático.');
        try {
            if (!flock($file, LOCK_EX)) throw new RuntimeException('El control del proceso está ocupado.');
            $state = self::decode((string) stream_get_contents($file));
            if ((int) $state['revision'] !== $expectedRevision) throw new RuntimeException('Otro usuario cambió el estado. Revisá el estado actual antes de continuar.');
            $state = ['enabled'=>$enabled, 'revision'=>$expectedRevision+1, 'changed_at'=>time(), 'changed_by'=>$userId];
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($file);
            if (fwrite($file, $json) !== strlen($json) || !ftruncate($file, strlen($json)) || !fflush($file)) {
                throw new RuntimeException('No se pudo guardar el control del proceso automático.');
            }
            return $state;
        } finally { fclose($file); }
    }
}
