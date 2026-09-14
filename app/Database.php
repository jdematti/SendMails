<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function configExists(): bool
    {
        return is_file(DB_CONFIG_PATH);
    }

    public static function loadConfig(): array
    {
        if (!self::configExists()) {
            return [
                'server' => '',
                'database' => '',
                'username' => '',
                'password' => '',
                'encrypt' => 'no',
                'trust_server_certificate' => true,
            ];
        }

        $json = file_get_contents(DB_CONFIG_PATH);
        $config = json_decode((string) $json, true);
        if (!is_array($config)) {
            return [];
        }

        return array_merge([
            'server' => '',
            'database' => '',
            'username' => '',
            'password' => '',
            'encrypt' => 'no',
            'trust_server_certificate' => true,
        ], $config);
    }

    public static function saveConfig(array $config): void
    {
        $payload = [
            'server' => trim((string) ($config['server'] ?? '')),
            'database' => trim((string) ($config['database'] ?? '')),
            'username' => trim((string) ($config['username'] ?? '')),
            'password' => (string) ($config['password'] ?? ''),
            'encrypt' => (string) ($config['encrypt'] ?? 'no'),
            'trust_server_certificate' => (bool) ($config['trust_server_certificate'] ?? true),
            'updated_at' => date('c'),
        ];

        if ($payload['server'] === '' || $payload['database'] === '' || $payload['username'] === '') {
            throw new InvalidArgumentException('Servidor, base de datos y usuario son obligatorios.');
        }

        file_put_contents(
            DB_CONFIG_PATH,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        self::$pdo = null;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = self::loadConfig();
        if (empty($config['server']) || empty($config['database']) || empty($config['username'])) {
            throw new RuntimeException('La conexion a SQL Server todavia no esta configurada.');
        }

        self::$pdo = self::connect($config);

        return self::$pdo;
    }

    public static function test(array $config): void
    {
        $pdo = self::connect($config);
        $pdo->query('SELECT 1');
    }

    public static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'sqlsrv:Server=%s;Database=%s;Encrypt=%s;TrustServerCertificate=%s',
            trim((string) ($config['server'] ?? '')),
            trim((string) ($config['database'] ?? '')),
            (string) ($config['encrypt'] ?? 'no'),
            !empty($config['trust_server_certificate']) ? 'yes' : 'no'
        );

        return new PDO($dsn, trim((string) ($config['username'] ?? '')), (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
        ]);
    }
}
