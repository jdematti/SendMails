<?php

declare(strict_types=1);

final class EmailExclusionRepository
{
    public static function countAll(): int
    {
        Schema::ensure();
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM dbo.SendMail_EmailExclusions')->fetchColumn();
    }

    public static function isExcluded(string $email): bool
    {
        $normalized = UnsubscribeRepository::normalizeEmail($email);
        if ($normalized === '') {
            return false;
        }

        Schema::ensure();
        $stmt = Database::pdo()->prepare('SELECT 1 FROM dbo.SendMail_EmailExclusions WHERE email_normalized = :email');
        $stmt->execute([':email' => $normalized]);
        return (bool) $stmt->fetchColumn();
    }

    public static function statusByEmail(array $emails): array
    {
        $blocked = self::excludedEmailSet($emails);
        $status = [];

        foreach ($emails as $email) {
            $normalized = UnsubscribeRepository::normalizeEmail(is_scalar($email) ? (string) $email : '');
            if ($normalized !== '') {
                $status[$normalized] = isset($blocked[$normalized]);
            }
        }

        return $status;
    }

    public static function filterAllowedRecipients(array $recipients): array
    {
        $emails = [];
        foreach ($recipients as $recipient) {
            $normalized = UnsubscribeRepository::normalizeEmail((string) ($recipient['email'] ?? $recipient['email_to'] ?? ''));
            if ($normalized !== '') {
                $emails[$normalized] = true;
            }
        }

        if (!$emails) {
            return $recipients;
        }

        $blocked = self::excludedEmailSet(array_keys($emails));
        if (!$blocked) {
            return $recipients;
        }

        return array_values(array_filter($recipients, static function (array $recipient) use ($blocked): bool {
            $normalized = UnsubscribeRepository::normalizeEmail((string) ($recipient['email'] ?? $recipient['email_to'] ?? ''));
            return $normalized === '' || !isset($blocked[$normalized]);
        }));
    }

    public static function setExcluded(array $recipient, bool $excluded): void
    {
        Schema::ensure();
        $email = trim((string) ($recipient['email'] ?? $recipient['email_to'] ?? ''));
        $normalized = UnsubscribeRepository::normalizeEmail($email);
        if ($normalized === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El cliente no tiene un email valido para excluir.');
        }

        if (!$excluded) {
            $stmt = Database::pdo()->prepare('DELETE FROM dbo.SendMail_EmailExclusions WHERE email_normalized = :email');
            $stmt->execute([':email' => $normalized]);
            return;
        }

        $stmt = Database::pdo()->prepare(
            "MERGE dbo.SendMail_EmailExclusions AS target
             USING (
                SELECT
                    :email AS email,
                    :email_normalized AS email_normalized,
                    :client_oid AS client_oid,
                    :client_code AS client_code,
                    :client_name AS client_name
             ) AS source
             ON target.email_normalized = source.email_normalized
             WHEN MATCHED THEN UPDATE SET
                email = source.email,
                client_oid = source.client_oid,
                client_code = source.client_code,
                client_name = source.client_name,
                updated_at = SYSDATETIME()
             WHEN NOT MATCHED THEN INSERT
                (email, email_normalized, client_oid, client_code, client_name)
                VALUES (source.email, source.email_normalized, source.client_oid, source.client_code, source.client_name);"
        );
        $stmt->execute([
            ':email' => $email,
            ':email_normalized' => $normalized,
            ':client_oid' => self::nullable((string) ($recipient['oid'] ?? $recipient['client_oid'] ?? '')),
            ':client_code' => self::nullable((string) ($recipient['codigo_cliente'] ?? $recipient['client_code'] ?? '')),
            ':client_name' => self::nullable((string) ($recipient['razon_social'] ?? $recipient['client_name'] ?? '')),
        ]);
    }

    private static function excludedEmailSet(array $emails): array
    {
        Schema::ensure();
        $emails = array_values(array_unique(array_filter(array_map(
            static fn ($email): string => UnsubscribeRepository::normalizeEmail(is_scalar($email) ? (string) $email : ''),
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

            $stmt = Database::pdo()->prepare(
                'SELECT email_normalized FROM dbo.SendMail_EmailExclusions WHERE email_normalized IN (' . implode(',', $placeholders) . ')'
            );
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $blocked[(string) $email] = true;
            }
        }

        return $blocked;
    }

    private static function nullable(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
