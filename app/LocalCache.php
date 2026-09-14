<?php
declare(strict_types=1);

final class LocalCache
{
    public static function remember(string $key, int $seconds, callable $read)
    {
        $path = STORAGE_PATH . '/cache';
        if (!is_dir($path)) mkdir($path, 0770, true);
        $file = $path . '/' . hash('sha256', $key) . '.json';
        if (is_file($file) && filemtime($file) > time() - $seconds) {
            $value = json_decode((string) file_get_contents($file), true);
            if (is_array($value) && array_key_exists('value', $value)) return $value['value'];
        }
        $value = $read();
        $temp = $file . '.' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, json_encode(['value' => $value], JSON_THROW_ON_ERROR), LOCK_EX) !== false) {
            if (!@rename($temp, $file)) @unlink($temp);
        }
        return $value;
    }
}
