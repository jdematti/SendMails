<?php

declare(strict_types=1);

final class Settings
{
    public static function smtp(?int $branchId = null): array
    {
        $defaults = [
            'host' => '',
            'port' => '587',
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_email' => '',
            'from_name' => '',
            'reply_to' => '',
            'interval_seconds' => 2,
        ];

        try {
            Schema::ensure();
            $settingKey = self::smtpSettingKey($branchId);
            $stmt = Database::pdo()->prepare('SELECT setting_value FROM dbo.SendMail_Settings WHERE setting_key = :key');
            $stmt->execute([':key' => $settingKey]);
            $value = $stmt->fetchColumn();
            if (!$value) {
                return $defaults;
            }

            $decoded = json_decode((string) $value, true);
            return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
        } catch (Throwable $e) {
            return $defaults;
        }
    }

    public static function app(): array
    {
        $defaults = [
            'base_url' => '',
        ];

        try {
            Schema::ensure();
            $stmt = Database::pdo()->prepare('SELECT setting_value FROM dbo.SendMail_Settings WHERE setting_key = :key');
            $stmt->execute([':key' => 'app']);
            $value = $stmt->fetchColumn();
            if (!$value) {
                return $defaults;
            }

            $decoded = json_decode((string) $value, true);
            return is_array($decoded) ? array_merge($defaults, $decoded) : $defaults;
        } catch (Throwable $e) {
            return $defaults;
        }
    }

    public static function saveApp(array $data): void
    {
        Schema::ensure();
        $payload = self::normalizeApp($data);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = Database::pdo()->prepare(
            "MERGE dbo.SendMail_Settings AS target
             USING (SELECT :setting_key AS setting_key, :setting_value AS setting_value) AS source
             ON target.setting_key = source.setting_key
             WHEN MATCHED THEN UPDATE SET setting_value = source.setting_value, updated_at = SYSDATETIME()
             WHEN NOT MATCHED THEN INSERT (setting_key, setting_value) VALUES (source.setting_key, source.setting_value);"
        );
        $stmt->execute([
            ':setting_key' => 'app',
            ':setting_value' => $json,
        ]);
    }

    public static function normalizeApp(array $data): array
    {
        return [
            'base_url' => rtrim(trim((string) ($data['base_url'] ?? '')), '/'),
        ];
    }

    public static function saveSmtp(array $data, ?int $branchId = null): void
    {
        Schema::ensure();
        $settingKey = self::smtpSettingKey($branchId);
        $payload = self::normalizeSmtp($data);

        if ($payload['host'] === '' || $payload['from_email'] === '') {
            throw new InvalidArgumentException('Servidor SMTP y email remitente son obligatorios.');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = Database::pdo()->prepare(
            "MERGE dbo.SendMail_Settings AS target
             USING (SELECT :setting_key AS setting_key, :setting_value AS setting_value) AS source
             ON target.setting_key = source.setting_key
             WHEN MATCHED THEN UPDATE SET setting_value = source.setting_value, updated_at = SYSDATETIME()
             WHEN NOT MATCHED THEN INSERT (setting_key, setting_value) VALUES (source.setting_key, source.setting_value);"
        );
        $stmt->execute([
            ':setting_key' => $settingKey,
            ':setting_value' => $json,
        ]);
    }

    public static function normalizeSmtp(array $data): array
    {
        return [
            'host' => trim((string) ($data['host'] ?? '')),
            'port' => (string) normalize_int((string) ($data['port'] ?? '587'), 587, 1, 65535),
            'username' => trim((string) ($data['username'] ?? '')),
            'password' => (string) ($data['password'] ?? ''),
            'encryption' => in_array(($data['encryption'] ?? 'tls'), ['tls', 'ssl', 'none'], true) ? $data['encryption'] : 'tls',
            'from_email' => trim((string) ($data['from_email'] ?? '')),
            'from_name' => trim((string) ($data['from_name'] ?? '')),
            'reply_to' => trim((string) ($data['reply_to'] ?? '')),
            'interval_seconds' => normalize_int((string) ($data['interval_seconds'] ?? '2'), 2, 0, 3600),
        ];
    }

    public static function whatsapp(?int $branchId = null): array
    {
        $defaults = [
            'graph_version' => 'v25.0',
            'business_id' => '',
            'app_id' => '',
            'waba_id' => '',
            'phone_number_id' => '',
            'display_phone' => '',
            'country_code' => '54',
            'test_phone' => '',
            'access_token' => '',
            'app_secret' => '',
            'verify_token' => '',
            'is_enabled' => false,
        ];

        try {
            Schema::ensure();
            $settingKey = self::whatsappSettingKey($branchId);
            $stmt = Database::pdo()->prepare('SELECT setting_value FROM dbo.SendMail_Settings WHERE setting_key = :key');
            $stmt->execute([':key' => $settingKey]);
            $value = $stmt->fetchColumn();
            if (!$value) {
                return $defaults;
            }

            $decoded = json_decode((string) $value, true);
            if (!is_array($decoded)) {
                return $defaults;
            }

            $config = array_merge($defaults, $decoded);
            $config['access_token'] = SecretBox::decrypt((string) ($decoded['access_token_cipher'] ?? ''));
            $config['app_secret'] = SecretBox::decrypt((string) ($decoded['app_secret_cipher'] ?? ''));
            $config['verify_token'] = SecretBox::decrypt((string) ($decoded['verify_token_cipher'] ?? ''));
            unset($config['access_token_cipher'], $config['app_secret_cipher'], $config['verify_token_cipher']);
            $config['is_enabled'] = !empty($config['is_enabled']);
            return $config;
        } catch (Throwable $e) {
            return $defaults;
        }
    }

    public static function saveWhatsapp(array $data, ?int $branchId = null): void
    {
        Schema::ensure();
        $settingKey = self::whatsappSettingKey($branchId);
        $existing = self::whatsapp($branchId);
        $graphVersion = trim((string) ($data['graph_version'] ?? 'v25.0'));
        if (!preg_match('/^v\d+\.\d+$/', $graphVersion)) {
            throw new InvalidArgumentException('La version de Graph API debe tener el formato v25.0.');
        }

        $accessToken = trim((string) ($data['access_token'] ?? ''));
        $appSecret = trim((string) ($data['app_secret'] ?? ''));
        $verifyToken = trim((string) ($data['verify_token'] ?? ''));
        if ($accessToken === '') {
            $accessToken = (string) ($existing['access_token'] ?? '');
        }
        if ($appSecret === '') {
            $appSecret = (string) ($existing['app_secret'] ?? '');
        }
        if ($verifyToken === '') {
            $verifyToken = (string) ($existing['verify_token'] ?? '');
        }
        if ($verifyToken === '') {
            $verifyToken = bin2hex(random_bytes(24));
        }

        $payload = [
            'graph_version' => $graphVersion,
            'business_id' => trim((string) ($data['business_id'] ?? '')),
            'app_id' => trim((string) ($data['app_id'] ?? '')),
            'waba_id' => trim((string) ($data['waba_id'] ?? '')),
            'phone_number_id' => trim((string) ($data['phone_number_id'] ?? '')),
            'display_phone' => trim((string) ($data['display_phone'] ?? '')),
            'country_code' => preg_replace('/\D+/', '', (string) ($data['country_code'] ?? '54')) ?: '54',
            'test_phone' => trim((string) ($data['test_phone'] ?? '')),
            'access_token_cipher' => $accessToken !== '' ? SecretBox::encrypt($accessToken) : '',
            'app_secret_cipher' => $appSecret !== '' ? SecretBox::encrypt($appSecret) : '',
            'verify_token_cipher' => SecretBox::encrypt($verifyToken),
            'is_enabled' => !empty($data['is_enabled']),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo preparar la configuracion de WhatsApp.');
        }

        $stmt = Database::pdo()->prepare(
            "MERGE dbo.SendMail_Settings AS target
             USING (SELECT :setting_key AS setting_key, :setting_value AS setting_value) AS source
             ON target.setting_key = source.setting_key
             WHEN MATCHED THEN UPDATE SET setting_value = source.setting_value, updated_at = SYSDATETIME()
             WHEN NOT MATCHED THEN INSERT (setting_key, setting_value) VALUES (source.setting_key, source.setting_value);"
        );
        $stmt->execute([
            ':setting_key' => $settingKey,
            ':setting_value' => $json,
        ]);
    }

    public static function whatsappConfigurations(): array
    {
        Schema::ensure();
        $stmt = Database::pdo()->query(
            "SELECT setting_key FROM dbo.SendMail_Settings WHERE setting_key LIKE 'whatsapp:%' ORDER BY setting_key"
        );
        $configs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $settingKey) {
            if (!preg_match('/^whatsapp:(\d+)$/', (string) $settingKey, $matches)) {
                continue;
            }
            $branchId = (int) $matches[1];
            $config = self::whatsapp($branchId);
            $config['branch_id'] = $branchId;
            $configs[] = $config;
        }
        return $configs;
    }

    public static function whatsappForWebhook(string $wabaId, string $phoneNumberId): ?array
    {
        foreach (self::whatsappConfigurations() as $config) {
            if ($phoneNumberId !== '' && hash_equals((string) ($config['phone_number_id'] ?? ''), $phoneNumberId)) {
                return $config;
            }
            if ($wabaId !== '' && hash_equals((string) ($config['waba_id'] ?? ''), $wabaId)) {
                return $config;
            }
        }
        return null;
    }

    public static function whatsappVerifyTokenMatches(string $verifyToken): bool
    {
        if ($verifyToken === '') {
            return false;
        }
        foreach (self::whatsappConfigurations() as $config) {
            $configured = (string) ($config['verify_token'] ?? '');
            if ($configured !== '' && hash_equals($configured, $verifyToken)) {
                return true;
            }
        }
        return false;
    }

    private static function smtpSettingKey(?int $branchId = null): string
    {
        $branchId = $branchId !== null && $branchId > 0 ? $branchId : (BranchRepository::currentId() ?? 0);
        if ($branchId <= 0) {
            $value = Database::pdo()
                ->query('SELECT TOP 1 id FROM dbo.SendMail_Branches WHERE is_active = 1 ORDER BY id ASC')
                ->fetchColumn();
            $branchId = $value !== false ? (int) $value : 0;
        }
        if ($branchId <= 0) {
            throw new RuntimeException('Selecciona una sucursal para configurar SMTP.');
        }

        return 'smtp:' . $branchId;
    }

    private static function whatsappSettingKey(?int $branchId = null): string
    {
        $branchId = $branchId !== null && $branchId > 0 ? $branchId : (BranchRepository::currentId() ?? 0);
        if ($branchId <= 0) {
            throw new RuntimeException('Selecciona una sucursal para configurar WhatsApp.');
        }
        return 'whatsapp:' . $branchId;
    }
}
