<?php

declare(strict_types=1);

final class InvoiceCrypto
{
    private const METHOD = 'aes-256-cbc';
    private const IV_BASE64 = 'V5EORWHLwCNQpUxyGzdn1A';

    public static function encrypt(string $value): string
    {
        $encrypted = openssl_encrypt($value, self::METHOD, self::key(), 0, base64_decode(self::IV_BASE64));
        if (!is_string($encrypted)) {
            throw new RuntimeException('No se pudo cifrar la URL de factura.');
        }

        return $encrypted;
    }

    private static function key(): string
    {
        $key = getenv('INVOICE_CRYPTO_KEY');
        if (is_string($key) && $key !== '') {
            return $key;
        }
        $path = __DIR__ . '/../storage/invoice_crypto.key';
        $key = is_file($path) ? trim((string) file_get_contents($path)) : '';
        if ($key === '') {
            throw new RuntimeException('Configura INVOICE_CRYPTO_KEY o storage/invoice_crypto.key con la clave del sistema de facturas.');
        }
        return $key;
    }

    public static function invoiceUrl(string $web, string $locPrefix, string $invoiceId): string
    {
        $web = trim($web);
        $locPrefix = strtolower(trim($locPrefix));
        if ($web === '' || $locPrefix === '' || $invoiceId === '') {
            throw new InvalidArgumentException('Web, prefijo de localidad e ID de factura son obligatorios.');
        }

        return 'https://facturas.' . $web . '/?l=' . rawurlencode(self::encrypt($locPrefix))
            . '&f=' . rawurlencode(self::encrypt($invoiceId));
    }
}
