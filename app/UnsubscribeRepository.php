<?php

declare(strict_types=1);

final class UnsubscribeRepository
{
    public static function countAll(): int
    {
        Schema::ensure();
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $whereSql = $branchWhere !== '' ? 'WHERE ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare("SELECT COUNT(*) FROM dbo.SendMail_Unsubscribes $whereSql");
        $stmt->execute($branchParams);
        return (int) $stmt->fetchColumn();
    }

    public static function recent(int $limit = 20): array
    {
        Schema::ensure();
        $limit = max(1, min($limit, 100));
        [$branchWhere, $branchParams] = BranchRepository::activeBranchWhere();
        $whereSql = $branchWhere !== '' ? 'WHERE ' . $branchWhere : '';
        $stmt = Database::pdo()->prepare("SELECT TOP $limit * FROM dbo.SendMail_Unsubscribes $whereSql ORDER BY created_at DESC, id DESC");
        $stmt->execute($branchParams);
        return $stmt->fetchAll();
    }

    public static function isUnsubscribed(string $email, ?int $branchId = null): bool
    {
        $normalized = self::normalizeEmail($email);
        if ($normalized === '') {
            return false;
        }

        Schema::ensure();
        $branchId = self::branchIdForLookup($branchId);
        $branchSql = $branchId !== null ? ' AND branch_id = :branch_id' : '';
        $stmt = Database::pdo()->prepare("SELECT 1 FROM dbo.SendMail_Unsubscribes WHERE email_normalized = :email $branchSql");
        $params = [':email' => $normalized];
        if ($branchId !== null) {
            $params[':branch_id'] = $branchId;
        }
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public static function statusByEmail(array $emails, ?int $branchId = null): array
    {
        $blocked = self::unsubscribedEmailSet($emails, $branchId);
        $status = [];

        foreach ($emails as $email) {
            $normalized = self::normalizeEmail(is_scalar($email) ? (string) $email : '');
            if ($normalized !== '') {
                $status[$normalized] = isset($blocked[$normalized]);
            }
        }

        return $status;
    }

    public static function filterSubscribedClients(array $clients, ?int $branchId = null): array
    {
        $lookupBranchId = self::branchIdForLookup($branchId);
        $emailsByBranch = [];

        foreach ($clients as $client) {
            $normalized = self::normalizeEmail((string) ($client['email'] ?? $client['email_to'] ?? ''));
            if ($normalized === '') {
                continue;
            }

            $clientBranchId = (int) ($client['branch_id'] ?? 0);
            $effectiveBranchId = $clientBranchId > 0 ? $clientBranchId : $lookupBranchId;
            $key = $effectiveBranchId !== null && $effectiveBranchId > 0 ? (string) $effectiveBranchId : 'all';
            $emailsByBranch[$key][$normalized] = true;
        }

        if (!$emailsByBranch) {
            return $clients;
        }

        $blockedByBranch = [];
        foreach ($emailsByBranch as $key => $emails) {
            $effectiveBranchId = $key === 'all' ? null : (int) $key;
            $blockedByBranch[$key] = self::unsubscribedEmailSet(array_keys($emails), $effectiveBranchId);
        }

        return array_values(array_filter($clients, static function (array $client) use ($blockedByBranch, $lookupBranchId): bool {
            $normalized = self::normalizeEmail((string) ($client['email'] ?? $client['email_to'] ?? ''));
            if ($normalized === '') {
                return true;
            }

            $clientBranchId = (int) ($client['branch_id'] ?? 0);
            $effectiveBranchId = $clientBranchId > 0 ? $clientBranchId : $lookupBranchId;
            $key = $effectiveBranchId !== null && $effectiveBranchId > 0 ? (string) $effectiveBranchId : 'all';
            return !isset($blockedByBranch[$key][$normalized]);
        }));
    }

    public static function tokenFor(array $recipient, string $source = 'campaign'): string
    {
        $email = trim((string) ($recipient['email'] ?? $recipient['email_to'] ?? ''));
        $normalized = self::normalizeEmail($email);
        if ($normalized === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('No se pudo generar el link de baja: email invalido.');
        }

        $branchId = self::branchIdFromRecipient($recipient);
        $payload = [
            'v' => 1,
            'source' => $source,
            'branch_id' => $branchId,
            'email' => $email,
            'email_normalized' => $normalized,
            'client_oid' => (string) ($recipient['oid'] ?? $recipient['client_oid'] ?? ''),
            'client_code' => (string) ($recipient['codigo_cliente'] ?? $recipient['client_code'] ?? ''),
            'client_name' => (string) ($recipient['razon_social'] ?? $recipient['client_name'] ?? ''),
            'issued_at' => time(),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('No se pudo preparar el token de baja.');
        }

        $encoded = self::base64UrlEncode($json);
        return $encoded . '.' . hash_hmac('sha256', $encoded, self::secret());
    }

    public static function urlFor(array $recipient, string $source = 'campaign'): string
    {
        return Auth::baseUrl() . '/unsubscribe.php?token=' . rawurlencode(self::tokenFor($recipient, $source));
    }

    public static function payloadFromToken(string $token): array
    {
        $token = trim($token);
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('El link de baja no es valido.');
        }

        $expected = hash_hmac('sha256', $parts[0], self::secret());
        if (!hash_equals($expected, $parts[1])) {
            throw new InvalidArgumentException('El link de baja no es valido.');
        }

        $json = self::base64UrlDecode($parts[0]);
        if (!is_string($json) || $json === '') {
            throw new InvalidArgumentException('El link de baja no es valido.');
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('El link de baja no es valido.');
        }

        $email = trim((string) ($payload['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El link de baja no incluye un email valido.');
        }

        $payload['email'] = $email;
        $payload['email_normalized'] = self::normalizeEmail($email);
        $payload['source'] = trim((string) ($payload['source'] ?? 'campaign')) ?: 'campaign';
        $payload['branch_id'] = self::branchIdFromPayload($payload);
        $payload['client_oid'] = (string) ($payload['client_oid'] ?? '');
        $payload['client_code'] = (string) ($payload['client_code'] ?? '');
        $payload['client_name'] = (string) ($payload['client_name'] ?? '');

        return $payload;
    }

    public static function unsubscribeWithToken(string $token, string $ipAddress = '', string $userAgent = ''): array
    {
        $payload = self::payloadFromToken($token);
        return self::unsubscribe($payload, $ipAddress, $userAgent);
    }

    public static function unsubscribe(array $payload, string $ipAddress = '', string $userAgent = ''): array
    {
        Schema::ensure();
        $email = trim((string) ($payload['email'] ?? ''));
        $normalized = self::normalizeEmail($email);
        if ($normalized === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email invalido.');
        }
        $branchId = self::branchIdFromPayload($payload);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM dbo.SendMail_Unsubscribes WHERE branch_id = :branch_id AND email_normalized = :email');
        $stmt->execute([
            ':branch_id' => $branchId,
            ':email' => $normalized,
        ]);
        $existing = $stmt->fetch();
        if ($existing) {
            return ['created' => false, 'row' => $existing, 'payload' => $payload];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO dbo.SendMail_Unsubscribes
             (branch_id, email, email_normalized, client_oid, client_code, client_name, source, ip_address, user_agent)
             OUTPUT INSERTED.*
             VALUES
             (:branch_id, :email, :email_normalized, :client_oid, :client_code, :client_name, :source, :ip_address, :user_agent)'
        );
        $stmt->execute([
            ':branch_id' => $branchId,
            ':email' => $email,
            ':email_normalized' => $normalized,
            ':client_oid' => self::nullable((string) ($payload['client_oid'] ?? '')),
            ':client_code' => self::nullable((string) ($payload['client_code'] ?? '')),
            ':client_name' => self::nullable((string) ($payload['client_name'] ?? '')),
            ':source' => substr(trim((string) ($payload['source'] ?? 'campaign')) ?: 'campaign', 0, 30),
            ':ip_address' => self::nullable(substr($ipAddress, 0, 80)),
            ':user_agent' => self::nullable(substr($userAgent, 0, 300)),
        ]);

        return ['created' => true, 'row' => $stmt->fetch(), 'payload' => $payload];
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function unsubscribedEmailSet(array $emails, ?int $branchId = null): array
    {
        Schema::ensure();
        $branchId = self::branchIdForLookup($branchId);
        $emails = array_values(array_unique(array_filter(array_map(
            static fn ($email): string => self::normalizeEmail(is_scalar($email) ? (string) $email : ''),
            $emails
        ))));
        if (!$emails) {
            return [];
        }

        $blocked = [];
        foreach (array_chunk($emails, 700) as $chunk) {
            $placeholders = [];
            $params = [];
            foreach ($chunk as $index => $email) {
                $key = ':email' . $index;
                $placeholders[] = $key;
                $params[$key] = $email;
            }

            $branchSql = $branchId !== null ? ' AND branch_id = :branch_id' : '';
            $stmt = Database::pdo()->prepare(
                'SELECT email_normalized FROM dbo.SendMail_Unsubscribes WHERE email_normalized IN (' . implode(',', $placeholders) . ')' . $branchSql
            );
            if ($branchId !== null) {
                $params[':branch_id'] = $branchId;
            }
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $blocked[(string) $email] = true;
            }
        }

        return $blocked;
    }

    private static function branchIdFromRecipient(array $recipient): int
    {
        $branchId = (int) ($recipient['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $branchId = (int) (BranchRepository::currentId() ?? BranchRepository::defaultId() ?? 0);
        }
        if ($branchId <= 0) {
            throw new RuntimeException('No se pudo generar el link de baja: sucursal no identificada.');
        }

        return $branchId;
    }

    private static function branchIdFromPayload(array $payload): int
    {
        $branchId = (int) ($payload['branch_id'] ?? 0);
        if ($branchId <= 0) {
            $branchId = (int) (BranchRepository::currentId() ?? BranchRepository::defaultId() ?? 0);
        }
        if ($branchId <= 0) {
            throw new RuntimeException('El link de baja no incluye una sucursal valida.');
        }

        return $branchId;
    }

    private static function branchIdForLookup(?int $branchId = null): ?int
    {
        if ($branchId !== null && $branchId > 0) {
            return $branchId;
        }

        $currentId = BranchRepository::currentId();
        return $currentId !== null && $currentId > 0 ? $currentId : null;
    }

    private static function secret(): string
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT setting_value FROM dbo.SendMail_Settings WHERE setting_key = :key');
        $stmt->execute([':key' => 'unsubscribe_secret']);
        $secret = (string) ($stmt->fetchColumn() ?: '');
        if (strlen($secret) >= 64) {
            return $secret;
        }

        $secret = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            "MERGE dbo.SendMail_Settings AS target
             USING (SELECT :setting_key AS setting_key, :setting_value AS setting_value) AS source
             ON target.setting_key = source.setting_key
             WHEN MATCHED THEN UPDATE SET setting_value = source.setting_value, updated_at = SYSDATETIME()
             WHEN NOT MATCHED THEN INSERT (setting_key, setting_value) VALUES (source.setting_key, source.setting_value);"
        );
        $stmt->execute([
            ':setting_key' => 'unsubscribe_secret',
            ':setting_value' => $secret,
        ]);

        return $secret;
    }

    private static function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value)
    {
        $base64 = strtr($value, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($base64, true);
    }
}
