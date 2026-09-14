<?php

declare(strict_types=1);

final class BranchRepository
{
    private static array $connections = [];

    public static function all(): array
    {
        Schema::ensure();
        return Database::pdo()
            ->query('SELECT * FROM dbo.SendMail_Branches ORDER BY is_active DESC, name ASC')
            ->fetchAll();
    }

    public static function active(): array
    {
        Schema::ensure();
        return Database::pdo()
            ->query('SELECT * FROM dbo.SendMail_Branches WHERE is_active = 1 ORDER BY name ASC')
            ->fetchAll();
    }

    public static function find(int $id): ?array
    {
        Schema::ensure();
        $stmt = Database::pdo()->prepare('SELECT * FROM dbo.SendMail_Branches WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function forUser(int $userId, bool $activeOnly = true): array
    {
        Schema::ensure();
        $activeSql = $activeOnly ? 'AND b.is_active = 1' : '';
        $stmt = Database::pdo()->prepare(
            "SELECT b.*
             FROM dbo.SendMail_Branches b
             INNER JOIN dbo.SendMail_UserBranches ub ON ub.branch_id = b.id
             WHERE ub.user_id = :user_id $activeSql
             ORDER BY b.name ASC"
        );
        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function idsForUser(int $userId): array
    {
        Schema::ensure();
        $stmt = Database::pdo()->prepare('SELECT branch_id FROM dbo.SendMail_UserBranches WHERE user_id = :user_id ORDER BY branch_id');
        $stmt->execute([':user_id' => $userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function saveUserBranches(int $userId, array $branchIds): void
    {
        Schema::ensure();
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), static fn (int $id): bool => $id > 0)));
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $delete = $pdo->prepare('DELETE FROM dbo.SendMail_UserBranches WHERE user_id = :user_id');
            $delete->execute([':user_id' => $userId]);

            if ($branchIds) {
                $insert = $pdo->prepare(
                    'INSERT INTO dbo.SendMail_UserBranches (user_id, branch_id)
                     SELECT :user_id, :branch_id
                     WHERE EXISTS (SELECT 1 FROM dbo.SendMail_Branches WHERE id = :branch_id_exists)'
                );
                foreach ($branchIds as $branchId) {
                    $insert->execute([
                        ':user_id' => $userId,
                        ':branch_id' => $branchId,
                        ':branch_id_exists' => $branchId,
                    ]);
                }
            }

            $pdo->commit();
            self::clearSelectedIfInvalid($userId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function create(array $data): int
    {
        Schema::ensure();
        $payload = self::normalize($data, true);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO dbo.SendMail_Branches
             (name, server, port, database_name, username, password_cipher, encrypt, trust_server_certificate, is_active, notes)
             OUTPUT INSERTED.id
             VALUES
             (:name, :server, :port, :database_name, :username, :password_cipher, :encrypt, :trust_server_certificate, :is_active, :notes)'
        );
        $stmt->execute($payload);
        return (int) $stmt->fetchColumn();
    }

    public static function update(int $id, array $data): void
    {
        Schema::ensure();
        $current = self::find($id);
        if (!$current) {
            throw new RuntimeException('Sucursal no encontrada.');
        }

        $payload = self::normalize($data, false, (string) ($current['password_cipher'] ?? ''));
        $payload[':id'] = $id;
        $stmt = Database::pdo()->prepare(
            'UPDATE dbo.SendMail_Branches
             SET name = :name,
                 server = :server,
                 port = :port,
                 database_name = :database_name,
                 username = :username,
                 password_cipher = :password_cipher,
                 encrypt = :encrypt,
                 trust_server_certificate = :trust_server_certificate,
                 is_active = :is_active,
                 notes = :notes,
                 updated_at = SYSDATETIME()
             WHERE id = :id'
        );
        $stmt->execute($payload);
        unset(self::$connections[$id]);
        self::clearSelectedForInactiveBranch($id);
    }

    public static function delete(int $id): void
    {
        Schema::ensure();
        $pdo = Database::pdo();
        $references = [
            'dbo.SendMail_Templates',
            'dbo.SendMail_InvoiceTemplates',
            'dbo.SendMail_Campaigns',
            'dbo.SendMail_Queue',
            'dbo.SendMail_Log',
            'dbo.SendMail_InvoiceBatches',
            'dbo.SendMail_InvoiceQueue',
            'dbo.SendMail_InvoiceLog',
        ];

        foreach ($references as $table) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE branch_id = :id");
            $stmt->execute([':id' => $id]);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException('La sucursal tiene plantillas, envios o logs asociados. Desactivala en lugar de eliminarla.');
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM dbo.SendMail_UserBranches WHERE branch_id = :id');
            $stmt->execute([':id' => $id]);
            $stmt = $pdo->prepare("DELETE FROM dbo.SendMail_Settings WHERE setting_key = :setting_key");
            $stmt->execute([':setting_key' => 'smtp:' . $id]);
            $stmt = $pdo->prepare('DELETE FROM dbo.SendMail_Branches WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $pdo->commit();
            unset(self::$connections[$id]);
            self::clearSelectedForInactiveBranch($id);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function test(array $data, ?int $currentId = null): void
    {
        $currentCipher = '';
        if ($currentId !== null && $currentId > 0) {
            $current = self::find($currentId);
            $currentCipher = is_array($current) ? (string) ($current['password_cipher'] ?? '') : '';
        }

        $payload = self::normalize($data, $currentId === null || $currentId <= 0, $currentCipher);
        Database::test([
            'server' => self::serverWithPort((string) $payload[':server'], $payload[':port'] === null ? null : (int) $payload[':port']),
            'database' => (string) $payload[':database_name'],
            'username' => (string) $payload[':username'],
            'password' => SecretBox::decrypt((string) $payload[':password_cipher']),
            'encrypt' => (string) $payload[':encrypt'],
            'trust_server_certificate' => (bool) $payload[':trust_server_certificate'],
        ]);
    }

    public static function pdo(?int $branchId = null): PDO
    {
        $branchId = $branchId !== null && $branchId > 0 ? $branchId : (self::currentId() ?? self::defaultId());
        if ($branchId === null || $branchId <= 0) {
            throw new RuntimeException('No hay sucursal seleccionada.');
        }

        if (isset(self::$connections[$branchId])) {
            return self::$connections[$branchId];
        }

        $branch = self::find($branchId);
        if (!$branch || !(bool) ($branch['is_active'] ?? false)) {
            throw new RuntimeException('La sucursal seleccionada no esta activa.');
        }

        self::$connections[$branchId] = Database::connect(self::connectionConfig($branch));
        return self::$connections[$branchId];
    }

    public static function connectionConfig(array $branch): array
    {
        return [
            'server' => self::serverWithPort((string) ($branch['server'] ?? ''), $branch['port'] === null ? null : (int) $branch['port']),
            'database' => (string) ($branch['database_name'] ?? ''),
            'username' => (string) ($branch['username'] ?? ''),
            'password' => SecretBox::decrypt((string) ($branch['password_cipher'] ?? '')),
            'encrypt' => (string) ($branch['encrypt'] ?? 'no'),
            'trust_server_certificate' => (bool) ($branch['trust_server_certificate'] ?? true),
        ];
    }

    public static function currentId(): ?int
    {
        $id = (int) ($_SESSION['branch_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    public static function selected(): ?array
    {
        $id = self::currentId();
        return $id !== null ? self::find($id) : null;
    }

    public static function ensureSelectedForUser(int $userId): bool
    {
        $currentId = self::currentId();
        if ($currentId !== null && self::userCanAccess($userId, $currentId)) {
            return true;
        }

        unset($_SESSION['branch_id']);
        $branches = self::forUser($userId, true);
        if (count($branches) === 1) {
            $_SESSION['branch_id'] = (int) $branches[0]['id'];
            return true;
        }

        return false;
    }

    public static function selectForUser(int $userId, int $branchId): void
    {
        if (!self::userCanAccess($userId, $branchId)) {
            throw new RuntimeException('No tienes permiso para acceder a esa sucursal.');
        }

        $_SESSION['branch_id'] = $branchId;
    }

    public static function userCanAccess(int $userId, int $branchId): bool
    {
        Schema::ensure();
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM dbo.SendMail_UserBranches ub
             INNER JOIN dbo.SendMail_Branches b ON b.id = ub.branch_id
             WHERE ub.user_id = :user_id AND ub.branch_id = :branch_id AND b.is_active = 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':branch_id' => $branchId,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public static function defaultId(): ?int
    {
        $value = Database::pdo()
            ->query('SELECT TOP 1 id FROM dbo.SendMail_Branches WHERE is_active = 1 ORDER BY id ASC')
            ->fetchColumn();
        return $value !== false ? (int) $value : null;
    }

    public static function activeBranchWhere(string $alias = ''): array
    {
        $branchId = self::currentId();
        if ($branchId === null) {
            if (PHP_SAPI !== 'cli') {
                return ['1 = 0', []];
            }

            return ['', []];
        }

        $prefix = $alias !== '' ? self::quoteAlias($alias) . '.' : '';
        return [$prefix . 'branch_id = :current_branch_id', [':current_branch_id' => $branchId]];
    }

    public static function normalizeBranchIdFromRows(array $rows): int
    {
        $branchId = 0;
        foreach ($rows as $row) {
            $rowBranchId = (int) ($row['branch_id'] ?? 0);
            if ($rowBranchId <= 0) {
                continue;
            }
            if ($branchId > 0 && $branchId !== $rowBranchId) {
                throw new RuntimeException('No se pueden mezclar registros de distintas sucursales en un mismo envio.');
            }
            $branchId = $rowBranchId;
        }

        return $branchId > 0 ? $branchId : (self::currentId() ?? 0);
    }

    public static function encryptPassword(string $password): string
    {
        return SecretBox::encrypt($password);
    }

    private static function normalize(array $data, bool $requirePassword, string $currentPasswordCipher = ''): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $server = trim((string) ($data['server'] ?? ''));
        $databaseName = trim((string) ($data['database_name'] ?? $data['database'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $port = normalize_int((string) ($data['port'] ?? '0'), 0, 0, 65535);
        $encrypt = in_array((string) ($data['encrypt'] ?? 'no'), ['yes', 'no'], true)
            ? (string) ($data['encrypt'] ?? 'no')
            : 'no';

        if ($name === '' || $server === '' || $databaseName === '' || $username === '') {
            throw new InvalidArgumentException('Nombre, servidor, base de datos y usuario son obligatorios.');
        }

        if ($requirePassword && $password === '') {
            throw new InvalidArgumentException('La contrasena de la base de datos es obligatoria.');
        }

        $passwordCipher = $password !== '' ? SecretBox::encrypt($password) : $currentPasswordCipher;
        if ($passwordCipher === '') {
            throw new InvalidArgumentException('La contrasena de la base de datos es obligatoria.');
        }

        return [
            ':name' => $name,
            ':server' => $server,
            ':port' => $port > 0 ? $port : null,
            ':database_name' => $databaseName,
            ':username' => $username,
            ':password_cipher' => $passwordCipher,
            ':encrypt' => $encrypt,
            ':trust_server_certificate' => !empty($data['trust_server_certificate']) ? 1 : 0,
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
            ':notes' => trim((string) ($data['notes'] ?? '')),
        ];
    }

    private static function serverWithPort(string $server, ?int $port): string
    {
        $server = trim($server);
        if ($port !== null && $port > 0 && strpos($server, ',') === false) {
            return $server . ',' . $port;
        }

        return $server;
    }

    private static function clearSelectedIfInvalid(int $userId): void
    {
        $currentId = self::currentId();
        if ($currentId !== null && !self::userCanAccess($userId, $currentId)) {
            unset($_SESSION['branch_id']);
        }
    }

    private static function clearSelectedForInactiveBranch(int $branchId): void
    {
        if (self::currentId() === $branchId) {
            $branch = self::find($branchId);
            if (!$branch || !(bool) ($branch['is_active'] ?? false)) {
                unset($_SESSION['branch_id']);
            }
        }
    }

    private static function quoteAlias(string $alias): string
    {
        return '[' . str_replace(']', ']]', $alias) . ']';
    }
}
