<?php

declare(strict_types=1);

final class SecretBox
{
    private const PREFIX = 'v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function encrypt(string $plainText): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('La extension OpenSSL es necesaria para cifrar credenciales.');
        }

        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $cipherText = openssl_encrypt($plainText, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($cipherText) || $tag === '') {
            throw new RuntimeException('No se pudo cifrar la credencial.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }

        if (strncmp($payload, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            return $payload;
        }

        if (!function_exists('openssl_decrypt')) {
            throw new RuntimeException('La extension OpenSSL es necesaria para descifrar credenciales.');
        }

        $binary = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if (!is_string($binary) || strlen($binary) < self::IV_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('La credencial cifrada no es valida.');
        }

        $iv = substr($binary, 0, self::IV_BYTES);
        $tag = substr($binary, self::IV_BYTES, self::TAG_BYTES);
        $cipherText = substr($binary, self::IV_BYTES + self::TAG_BYTES);
        $plainText = openssl_decrypt($cipherText, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if (!is_string($plainText)) {
            throw new RuntimeException('No se pudo descifrar la credencial.');
        }

        return $plainText;
    }

    private static function key(): string
    {
        if (!is_dir(STORAGE_PATH)) {
            mkdir(STORAGE_PATH, 0775, true);
        }

        if (!is_file(BRANCH_SECRET_KEY_PATH)) {
            file_put_contents(BRANCH_SECRET_KEY_PATH, base64_encode(random_bytes(32)));
        }

        $encoded = trim((string) file_get_contents(BRANCH_SECRET_KEY_PATH));
        $key = base64_decode($encoded, true);
        if (!is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('La clave local de credenciales no es valida.');
        }

        return $key;
    }
}
